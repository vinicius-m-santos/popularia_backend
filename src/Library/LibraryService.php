<?php

declare(strict_types=1);

namespace App\Library;

use App\Api\ApiProblem;
use App\Api\Library\CollectionInput;
use App\Api\Library\DocumentInput;
use App\Auth\AdminAudit;
use App\Content\TextNormalizer;
use App\Entity\ContentReport;
use App\Entity\LibraryCollection;
use App\Entity\LibraryDocument;
use App\Entity\LibraryFile;
use App\Entity\LibrarySave;
use App\Entity\LibraryShare;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\LibraryAccess as Access;
use App\Enum\Topic;
use App\Repository\ComunaRepository;
use App\Security\RateLimitGuard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final readonly class LibraryService
{
    public function __construct(
        private EntityManagerInterface $em,
        private LibraryAccess $policy,
        private \App\Content\RemovedContentFilter $filter,
        private ComunaRepository $comunas,
        private ClockInterface $clock,
        private RateLimitGuard $rateLimit,
        private PdfValidator $validator,
        private ObjectStorage $storage,
        private AdminAudit $audit,
    ) {
    }

    public function collection(string $id): LibraryCollection
    {
        return (Uuid::isValid($id) ? $this->em->find(LibraryCollection::class, Uuid::fromString($id)) : null) ?? throw ApiProblem::notFound('Coleção não encontrada.');
    }

    public function upload(User $actor, DocumentInput $input, ?UploadedFile $upload): LibraryDocument
    {
        if (!$actor->isEmailVerified()) {
            throw ApiProblem::emailNotVerified();
        }
        $this->rateLimit->consume('library_upload', $actor->getUserIdentifier());
        $clientId = $input->clientId ?? throw ApiProblem::badRequest('/errors/library-upload-id', 'Identifique o envio para poder tentar novamente com segurança.');
        $existing = $this->em->getRepository(LibraryFile::class)->findOneBy(['uploadedBy' => $actor, 'clientId' => $clientId]);
        if (null !== $existing && 'ready' === $existing->status) {
            $document = $this->em->getRepository(LibraryDocument::class)->findOneBy(['file' => $existing]) ?? throw ApiProblem::notFound();
            $this->policy->assertRead($document, $actor);

            return $document;
        }
        $info = $this->validator->validate($upload);
        \assert(null !== $upload);
        $collection = $this->collection($input->collectionId ?? '');
        $this->assertAdd($collection, $actor);
        $this->filter->assertPdf($info['checksum'], $collection->comuna);
        $access = Access::from($input->access ?? (null === $collection->comuna ? 'private' : 'members'));
        $this->validateAccess($collection, $access, $actor);
        if (($input->isPublic ?? false) && null !== $collection->comuna) {
            $this->policy->moderation->assertModerator($collection->comuna, $actor);
        }
        if (null !== $collection->comuna && [] !== $input->sharedWith) {
            throw ApiProblem::badRequest('/errors/library-origin', 'Só documentos pessoais podem ser compartilhados com outras comunas.');
        }
        $file = $this->em->getConnection()->transactional(function () use ($actor, $clientId, $upload, $info): LibraryFile {
            $this->em->getConnection()->executeQuery('SELECT id FROM app_user WHERE id = ? FOR NO KEY UPDATE', [$actor->getId()->toRfc4122()])->fetchOne();
            $file = $this->em->getRepository(LibraryFile::class)->findOneBy(['uploadedBy' => $actor, 'clientId' => $clientId]);
            if (null !== $file && !in_array($file->status, ['failed', 'purged'], true)) {
                throw ApiProblem::conflict('/errors/library-upload-pending', 'Este envio já está em andamento. Aguarde antes de tentar novamente.');
            }
            if (null === $file) {
                $filename = mb_substr(basename(str_replace('\\', '/', $upload->getClientOriginalName())), 0, 255);
                $file = new LibraryFile($actor, $clientId, 'library/'.Uuid::v7()->toRfc4122().'.pdf', $filename, $info['checksum'], $info['byteSize'], $info['pageCount'], $this->clock->now());
                $this->em->persist($file);
            } else {
                if (!hash_equals($file->checksum, $info['checksum'])) {
                    throw ApiProblem::conflict('/errors/library-upload-file-changed', 'O arquivo mudou. Selecione-o novamente para iniciar outro envio.');
                }
                $file->checksum = $info['checksum'];
                $file->byteSize = $info['byteSize'];
                $file->pageCount = $info['pageCount'];
                $file->originalFilename = mb_substr(basename($upload->getClientOriginalName()), 0, 255);
                $file->status = 'pending';
                $file->createdAt = $this->clock->now();
            }
            $this->em->flush();

            return $file;
        });
        try {
            $this->storage->put($file->storageKey, $upload->getPathname(), $file->checksum);

            return $this->em->getConnection()->transactional(function () use ($collection, $actor, $input, $access, $file): LibraryDocument {
                if (null !== $collection->comuna) {
                    $this->comunas->lock($collection->comuna);
                }
                $this->lockCollection($collection);
                $this->assertAdd($collection, $actor);
                if (($input->isPublic ?? false) && null !== $collection->comuna) {
                    $this->policy->moderation->assertModerator($collection->comuna, $actor);
                }
                $document = new LibraryDocument($file, $collection, $collection->user, $collection->comuna,
                    TextNormalizer::normalize($input->title ?? ''), TextNormalizer::normalize($input->description ?? ''), $access,
                    $input->isPublic ?? false, null === $input->topic ? null : Topic::from($input->topic), $this->clock->now(),
                    $this->normalizeAuthor($input->author));
                if ('' === $document->title) {
                    throw new ApiProblem(422, '/errors/library-title', 'Dê um nome ao documento.');
                }
                $this->em->persist($document);
                $file->status = 'ready';
                $sharedWith = array_unique($input->sharedWith);
                sort($sharedWith);
                foreach ($sharedWith as $slug) {
                    $this->rateLimit->consume('content_creation', $actor->getUserIdentifier());
                    $this->shareInside($document, $slug, $actor);
                }
                $this->em->flush();

                return $document;
            });
        } catch (\Throwable $error) {
            // O recibo permanece até a limpeza, inclusive se a remoção do objeto falhar.
            $this->em->getConnection()->executeStatement("UPDATE library_file SET status = 'failed' WHERE id = ? AND status <> 'ready'", [$file->id->toRfc4122()]);
            try {
                $this->storage->delete($file->storageKey);
            } catch (\Throwable) {
                // app:library:purge tenta de novo. Nenhuma entidade final foi publicada.
            }
            throw $error;
        }
    }

    public function edit(string $id, User $actor, DocumentInput $input): LibraryDocument
    {
        return $this->mutate($id, function (LibraryDocument $document) use ($actor, $input): LibraryDocument {
            $this->policy->assertManage($document, $actor);
            if (ContentStatus::Published !== $document->status) {
                throw ApiProblem::notFound();
            }
            if (null !== $input->title) {
                $document->title = TextNormalizer::normalize($input->title);
                if ('' === $document->title) {
                    throw new ApiProblem(422, '/errors/library-title', 'Dê um nome ao documento.');
                }
            }
            if (null !== $input->description) {
                $document->description = TextNormalizer::normalize($input->description);
            }
            if ($input->clearAuthor) {
                $document->author = null;
            } elseif (null !== $input->author) {
                $document->author = $this->normalizeAuthor($input->author);
            }
            if (null !== $input->collectionId) {
                $collection = $this->collection($input->collectionId);
                if ($collection->user?->getId()->toRfc4122() !== $document->user?->getId()->toRfc4122()
                    || $collection->comuna?->getId()->toRfc4122() !== $document->comuna?->getId()->toRfc4122()) {
                    throw ApiProblem::badRequest('/errors/library-origin', 'Escolha uma coleção da biblioteca de origem.');
                }
                $this->lockCollection($collection);
                $document->collection = $collection;
            }
            if (null !== $input->access) {
                $this->validateAccess($document->collection, Access::from($input->access), $actor);
                $document->access = Access::from($input->access);
            }
            if (null !== $input->isPublic && $input->isPublic !== $document->isPublic) {
                if (!$this->policy->publicizes($document, $actor)) {
                    throw ApiProblem::forbidden('/errors/library-publication-permission', 'Só a propriedade ou a moderação da comuna pode disponibilizar este PDF na Biblioteca Pública.');
                }
                $document->isPublic = $input->isPublic;
            }
            if (null !== $input->topic || $input->clearTopic) {
                $document->topic = null === $input->topic ? null : Topic::from($input->topic);
            }
            $document->updatedAt = $this->clock->now();

            return $document;
        });
    }

    public function delete(string $id, User $actor): void
    {
        $this->mutate($id, function (LibraryDocument $document) use ($actor): void {
            $this->policy->assertManage($document, $actor, true);
            if (ContentStatus::Published === $document->status) {
                $document->status = ContentStatus::Deleted;
                $document->removedAt = $this->clock->now();
                $document->removedBy = $actor;
                $document->updatedAt = $this->clock->now();
            }
        });
    }

    public function save(string $id, User $actor, bool $saved): void
    {
        if (!$saved) {
            if (Uuid::isValid($id)) {
                $this->em->getConnection()->executeStatement('DELETE FROM library_save WHERE user_id = ? AND document_id = ?', [$actor->getId()->toRfc4122(), $id]);
            }

            return;
        }
        $this->mutate($id, function (LibraryDocument $document) use ($actor): void {
            $existing = $this->em->getRepository(LibrarySave::class)->findOneBy(['user' => $actor, 'document' => $document]);
            $this->policy->assertRead($document, $actor);
            if (null === $existing) {
                $this->em->persist(new LibrarySave($actor, $document, $this->clock->now()));
            }
        });
    }

    public function share(string $id, string $slug, User $actor, bool $active): void
    {
        $comuna = $this->comunas->findOneBySlug($slug) ?? throw ApiProblem::notFound();
        $this->em->getConnection()->transactional(function () use ($id, $slug, $actor, $active, $comuna): void {
            $this->comunas->lock($comuna);
            $this->mutate($id, function (LibraryDocument $document) use ($slug, $actor, $active, $comuna): void {
                $isOwner = null !== $document->user && $document->user->getId()->equals($actor->getId());
                if ($active) {
                    if (!$isOwner || ContentStatus::Published !== $document->status) {
                        throw ApiProblem::notFound();
                    }
                    $this->rateLimit->consume('content_creation', $actor->getUserIdentifier());
                    $this->shareInside($document, $slug, $actor);
                } else {
                    if (!$isOwner) {
                        throw ApiProblem::notFound('Documento não encontrado.');
                    }
                    $share = $this->em->getRepository(LibraryShare::class)->findOneBy(['document' => $document, 'comuna' => $comuna]);
                    if (null !== $share && ContentStatus::Published === $share->status) {
                        $this->em->remove($share);
                    }
                }
            });
        });
    }

    private function shareInside(LibraryDocument $document, string $slug, User $actor): void
    {
        $comuna = $this->comunas->findOneBySlug($slug) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $this->comunas->lock($comuna);
        $this->policy->moderation->assertNotArchived($comuna);
        $membership = $this->policy->memberships->findMembership($comuna, $actor);
        if (null === $membership || null === $document->user || !$document->user->getId()->equals($actor->getId())) {
            throw ApiProblem::forbidden('/errors/not-a-member', 'Participe da comuna para compartilhar um PDF pessoal nela.');
        }
        $existing = $this->em->getRepository(LibraryShare::class)->findOneBy(['document' => $document, 'comuna' => $comuna]);
        if (null !== $existing && ContentStatus::Removed === $existing->status) {
            throw ApiProblem::conflict('/errors/library-share-removed', 'A moderação retirou este PDF desta comuna.');
        }
        if (null === $existing) {
            $this->em->persist(new LibraryShare($document, $comuna, $membership, $this->clock->now()));
        }
    }

    public function createCollection(User $actor, CollectionInput $input): LibraryCollection
    {
        return $this->em->getConnection()->transactional(function () use ($actor, $input): LibraryCollection {
            $comuna = null === $input->comunaSlug ? null : ($this->comunas->findOneBySlug($input->comunaSlug) ?? throw ApiProblem::notFound());
            if (null !== $comuna) {
                $this->comunas->lock($comuna);
            }
            $collection = new LibraryCollection(null === $comuna ? $actor : null, $comuna, $actor,
                TextNormalizer::normalize($input->name), TextNormalizer::normalize($input->description), $this->clock->now());
            $this->assertAdd($collection, $actor);
            $this->rateLimit->consume('content_creation', $actor->getUserIdentifier());
            $this->em->persist($collection);
            $this->em->flush();

            return $collection;
        });
    }

    public function changeCollection(string $id, User $actor, ?CollectionInput $input): ?LibraryCollection
    {
        return $this->em->getConnection()->transactional(function () use ($id, $actor, $input): ?LibraryCollection {
            $collection = $this->collection($id);
            if (null !== $collection->comuna) {
                $this->comunas->lock($collection->comuna);
            }
            $this->lockCollection($collection);
            if (!$this->policy->managesCollection($collection, $actor)) {
                throw ApiProblem::notFound('Coleção não encontrada.');
            }
            if (null !== $input) {
                $this->assertAdd($collection, $actor);
                if (null !== $input->comunaSlug && $input->comunaSlug !== $collection->comuna?->getSlug()) {
                    throw ApiProblem::badRequest('/errors/library-origin', 'A origem da coleção não pode mudar.');
                }
                $collection->name = TextNormalizer::normalize($input->name);
                $collection->description = TextNormalizer::normalize($input->description);
                $collection->updatedAt = $this->clock->now();
            } else {
                if ($this->em->getRepository(LibraryDocument::class)->count(['collection' => $collection]) > 0) {
                    throw ApiProblem::conflict('/errors/library-collection-not-empty', 'Mova os documentos para outra coleção antes de excluir esta.', 'Documentos removidos também preservam a coleção para manter o histórico.');
                }
                $this->em->remove($collection);
            }
            $this->em->flush();

            return null === $input ? null : $collection;
        });
    }

    public function assertAdd(LibraryCollection $collection, User $actor): void
    {
        if (!$actor->isEmailVerified()) {
            throw ApiProblem::emailNotVerified();
        }
        if (null !== $collection->user && !$collection->user->getId()->equals($actor->getId())) {
            throw ApiProblem::notFound('Coleção não encontrada.');
        }
        if (null !== $collection->comuna) {
            $this->policy->moderation->assertNotArchived($collection->comuna);
            if (null === $this->policy->memberships->findMembership($collection->comuna, $actor)) {
                throw ApiProblem::forbidden('/errors/not-a-member', 'Participe da comuna para adicionar documentos ou coleções.');
            }
        }
    }

    private function lockCollection(LibraryCollection $collection): void
    {
        if (false === $this->em->getConnection()->executeQuery('SELECT id FROM library_collection WHERE id = ? FOR UPDATE', [$collection->id->toRfc4122()])->fetchOne()) {
            throw ApiProblem::notFound('A coleção não está mais disponível. Escolha outra coleção.');
        }
        $this->em->refresh($collection);
    }

    private function validateAccess(LibraryCollection $collection, Access $access, User $actor): void
    {
        if (Access::Moderators === $access && null !== $collection->comuna) {
            $this->policy->moderation->assertModerator($collection->comuna, $actor);
        }
        $allowed = null === $collection->comuna ? [Access::Private, Access::Followers]
            : ($collection->comuna->isPublic() ? [Access::Visitors, Access::Members, Access::Moderators] : [Access::Members, Access::Moderators]);
        if (!in_array($access, $allowed, true)) {
            throw new ApiProblem(422, '/errors/library-access', 'Este acesso não é permitido na biblioteca de origem.');
        }
    }

    private function normalizeAuthor(?string $author): ?string
    {
        if (null === $author) {
            return null;
        }
        $normalized = TextNormalizer::normalize($author);

        return '' === $normalized ? null : $normalized;
    }

    public function report(string $id, User $actor, string $reason, ?string $slug): void
    {
        $this->mutate($id, function (LibraryDocument $document) use ($actor, $reason, $slug): void {
            $this->policy->assertRead($document, $actor);
            $this->rateLimit->consume('content_creation', $actor->getUserIdentifier());
            $comuna = $document->comuna;
            if (null !== $slug) {
                $comuna = $this->comunas->findOneBySlug($slug) ?? throw ApiProblem::notFound();
                if ($comuna !== $document->comuna && null === $this->em->getRepository(LibraryShare::class)->findOneBy(['document' => $document, 'comuna' => $comuna])) {
                    throw ApiProblem::notFound();
                }
                if (!$comuna->isPublic() && null === $this->policy->memberships->findMembership($comuna, $actor)) {
                    throw ApiProblem::notFound();
                }
            }
            if (null === $this->em->getRepository(ContentReport::class)->findOneBy(['reporter' => $actor, 'targetType' => 'library_document', 'targetId' => $document->id])) {
                $this->em->persist(new ContentReport($comuna, $actor, 'library_document', $document->id, TextNormalizer::normalize($reason), $this->clock->now()));
            }
        });
    }

    public function remove(string $id, User $actor, string $reason, ?string $slug): void
    {
        $this->mutate($id, function (LibraryDocument $document) use ($actor, $reason, $slug): void {
            $platform = in_array($actor->getPlatformRole()->value, ['admin', 'platform_moderator'], true);
            $comuna = null === $slug ? $document->comuna : ($this->comunas->findOneBySlug($slug) ?? throw ApiProblem::notFound());
            if (!$platform) {
                if (null === $comuna) {
                    throw ApiProblem::forbidden('/errors/not-comuna-moderator', 'Só a moderação responsável pode remover este conteúdo.');
                }
                $membership = $this->policy->moderation->assertModerator($comuna, $actor);
                if (!$this->policy->moderation->canRemoveContentBy($comuna, $membership, $document->file->uploadedBy)) {
                    throw ApiProblem::forbidden('/errors/cannot-moderate-content', 'Você não pode remover este conteúdo.');
                }
            } elseif (!$document->isPublic && null === $this->em->getRepository(ContentReport::class)->findOneBy(['targetType' => 'library_document', 'targetId' => $document->id, 'status' => 'pending'])) {
                throw ApiProblem::notFound();
            }
            if (!$platform && null !== $document->user) {
                $share = $this->em->getRepository(LibraryShare::class)->findOneBy(['document' => $document, 'comuna' => $comuna]) ?? throw ApiProblem::notFound();
                $share->status = ContentStatus::Removed;
            } else {
                if (!$platform && $comuna->getId()->toRfc4122() !== $document->comuna?->getId()->toRfc4122()) {
                    throw ApiProblem::notFound();
                }
                $document->status = ContentStatus::Removed;
                $document->removedAt = $this->clock->now();
                $document->removedBy = $actor;
                $document->removalReason = TextNormalizer::normalize($reason);
                $document->updatedAt = $this->clock->now();
            }
            $this->em->flush();
            $this->audit->record($actor, 'library.document_removed', $document->file->uploadedBy->getId(), TextNormalizer::normalize($reason), ['document_id' => $document->id->toRfc4122(), 'comuna_id' => $comuna?->getId()->toRfc4122(), 'platform' => $platform]);
        });
    }

    /** @template T
     * @param callable(LibraryDocument): T $callback
     *
     * @return T
     */
    private function mutate(string $id, callable $callback): mixed
    {
        return $this->em->getConnection()->transactional(function () use ($id, $callback): mixed {
            $document = $this->policy->find($id);
            if (null !== $document->comuna) {
                $this->comunas->lock($document->comuna);
            }
            $this->em->refresh($document, LockMode::PESSIMISTIC_WRITE);
            $result = $callback($document);
            $this->em->flush();

            return $result;
        });
    }
}
