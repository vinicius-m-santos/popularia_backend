<?php

declare(strict_types=1);

namespace App\Library;

use App\Api\Library\DocumentView;
use App\Entity\LibraryDocument;
use App\Entity\LibrarySave;
use App\Entity\LibraryShare;
use App\Entity\User;
use App\Enum\ContentStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class LibraryViews
{
    public function __construct(private EntityManagerInterface $em, private LibraryQueries $queries, private LibraryAccess $policy)
    {
    }

    public function one(LibraryDocument $document, ?User $viewer): DocumentView
    {
        return $this->many([$document], $viewer)[0];
    }

    /** @param list<LibraryDocument> $documents
     * @return list<DocumentView>
     */
    public function many(array $documents, ?User $viewer): array
    {
        if ([] === $documents) {
            return [];
        }
        $readable = null === $viewer ? [] : $this->queries->readableIds($documents, $viewer);
        $cards = null === $viewer ? [] : array_fill_keys($this->em->getConnection()->executeQuery('SELECT d.id FROM '.LibraryAccess::FROM.' WHERE d.id IN (:ids) AND ('.LibraryAccess::visibleCard().')', [
            'ids' => array_map(static fn (LibraryDocument $d) => $d->id->toRfc4122(), $documents), 'viewer' => $viewer->getId()->toRfc4122(),
        ], ['ids' => ArrayParameterType::STRING])->fetchFirstColumn(), true);
        $saves = null === $viewer ? [] : $this->em->getRepository(LibrarySave::class)->findBy(['user' => $viewer, 'document' => $documents]);
        $saved = [];
        foreach ($saves as $save) {
            $saved[$save->document->id->toRfc4122()] = true;
        }
        $comunas = array_values(array_filter(array_map(static fn (LibraryDocument $d) => $d->comuna, $documents)));
        $memberships = null === $viewer ? [] : $this->policy->memberships->findForComunas($viewer, $comunas);
        /** @var list<LibraryShare> $shares */
        $shares = $this->em->createQueryBuilder()->select('s', 'c')->from(LibraryShare::class, 's')->join('s.comuna', 'c')->where("s.document IN (:docs) AND s.status = 'published'")->setParameter('docs', $documents)->getQuery()->getResult();
        $distributions = [];
        foreach ($shares as $share) {
            $distributions[$share->document->id->toRfc4122()][] = ['slug' => $share->comuna->getSlug(), 'name' => $share->comuna->getName(), 'visibility' => $share->comuna->getVisibility()->value];
        }

        return array_map(function (LibraryDocument $document) use ($viewer, $readable, $cards, $saved, $memberships, $distributions): DocumentView {
            $id = $document->id->toRfc4122();
            if (!isset($readable[$id])) {
                $active = ContentStatus::Published === $document->status;

                return new DocumentView($id, $active ? 'locked' : 'unavailable', title: isset($cards[$id]) ? $document->title : null,
                    origin: isset($cards[$id]) && null !== $document->comuna ? ['kind' => 'comuna', 'id' => $document->comuna->getId()->toRfc4122(), 'slug' => $document->comuna->getSlug(), 'name' => 'c/ '.$document->comuna->getName()] : null,
                    saved: isset($saved[$id]), message: 'Este documento não está mais disponível para você. Ver uma publicação com este PDF não dá acesso ao arquivo.');
            }
            $membership = null === $document->comuna ? null : ($memberships[$document->comuna->getId()->toRfc4122()] ?? null);
            $personalOwner = null !== $document->user && $document->user->getId()->equals($viewer->getId());
            $moderates = null !== $membership && $membership->getRole()->canModerate();
            $manages = $personalOwner || (null !== $membership && ($moderates || $document->file->uploadedBy->getId()->equals($viewer->getId())));
            $active = null === $document->comuna || $document->comuna->isActive();
            $author = $document->file->uploadedBy;
            $origin = null === $document->comuna
                ? ['kind' => 'personal', 'id' => $document->user?->getId()->toRfc4122(), 'name' => 'Biblioteca de '.$document->user?->getDisplayName()]
                : ['kind' => 'comuna', 'id' => $document->comuna->getId()->toRfc4122(), 'slug' => $document->comuna->getSlug(), 'name' => 'c/ '.$document->comuna->getName()];

            return new DocumentView($id, 'available', $document->title, $document->author, $document->description, $origin,
                ['id' => $document->collection->id->toRfc4122(), 'name' => $document->collection->name],
                \App\Enum\AccountStatus::Deleted === $author->getStatus() ? null : ['id' => $author->getId()->toRfc4122(), 'handle' => $author->getHandle(), 'name' => $author->getDisplayName()],
                $document->createdAt, $document->file->byteSize, $document->file->pageCount, $document->topic?->value,
                $document->access->value, $document->isPublic, isset($saved[$id]),
                ['canEdit' => $manages && $active, 'canDelete' => $manages && ($active || $moderates), 'canPublicize' => ($personalOwner || $moderates) && $active, 'canShare' => $personalOwner,
                    'canRemove' => $moderates && $this->policy->moderation->canRemoveContentBy($document->comuna, $membership, $author)],
                $manages ? ($distributions[$id] ?? []) : null);
        }, $documents);
    }
}
