<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ContentPolicy;
use App\Content\TextNormalizer;
use App\Entity\Post;
use App\Enum\Topic;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<PostInput, PostView> */
final readonly class PostCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private ContentPolicy $policy,
        private \App\Content\RemovedContentFilter $filter,
        private \App\Library\LibraryAccess $library,
        private PostViews $views,
        private RateLimitGuard $rateLimit,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PostView
    {
        return $this->em->getConnection()->transactional(function () use ($data): PostView {
            $author = $this->currentUser->get();
            $comuna = null;
            if (null !== $data->comunaId) {
                $comuna = $this->comunas->find(Uuid::fromString($data->comunaId)) ?? throw ApiProblem::notFound('Comuna não encontrada.');
            }
            if (null !== $comuna && null !== $data->topic) {
                throw ApiProblem::badRequest('/errors/inherited-topic', 'O assunto é herdado da comuna.');
            }
            $this->policy->assertCanPostIn($comuna, $author);
            $this->rateLimit->consume('content_creation', $author->getUserIdentifier());

            $this->filter->assertText($data->body, $comuna);
            $post = new Post($author, $comuna, TextNormalizer::normalize($data->body), $this->clock->now());
            $post->setTopic(null === $data->topic ? null : Topic::from($data->topic));
            if (null !== $data->documentId) {
                $document = $this->library->find($data->documentId);
                $this->em->refresh($document, \Doctrine\DBAL\LockMode::PESSIMISTIC_READ);
                $this->library->assertRead($document, $author);
                $post->libraryDocument = $document;
            }
            if (null !== $data->mediaId) {
                if (!Uuid::isValid($data->mediaId)) {
                    throw ApiProblem::badRequest('/errors/post-media', 'Mídia inválida.');
                }
                $media = $this->em->find(\App\Entity\PostMedia::class, Uuid::fromString($data->mediaId), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                if (null === $media || !$media->uploadedBy->getId()->equals($author->getId()) || 'ready' !== $media->status) {
                    throw ApiProblem::notFound('Mídia não encontrada.');
                }
                $alreadyAttached = $this->em->getRepository(Post::class)->findOneBy(['media' => $media]);
                if (null !== $alreadyAttached) {
                    throw ApiProblem::conflict('/errors/post-media-attached', 'Esta mídia já foi publicada.');
                }
                $post->media = $media;
            }
            $this->em->persist($post);
            $this->em->flush();

            return $this->views->one($post, $author);
        });
    }
}
