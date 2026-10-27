<?php

declare(strict_types=1);

namespace App\Api\Moderation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ContentPolicy;
use App\Content\TextNormalizer;
use App\Entity\Comment;
use App\Entity\ContentReport;
use App\Entity\User;
use App\Repository\UserBlockRepository;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<ReportInput, null> */
final readonly class ReportProcessor implements ProcessorInterface
{
    public function __construct(private CurrentUser $currentUser, private EntityManagerInterface $em, private ContentPolicy $policy, private UserBlockRepository $blocks, private RateLimitGuard $limits, private ClockInterface $clock)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $actor = $this->currentUser->get();
        $this->limits->consume('content_creation', $actor->getUserIdentifier());
        $id = Uuid::fromString($data->targetId);
        $comuna = null;
        if ('post' === $data->targetType) {
            $post = $this->policy->findVisiblePost($data->targetId, $actor);
            $author = $post->getAuthor();
            $comuna = $post->getComuna();
        } elseif ('comment' === $data->targetType) {
            $comment = $this->em->find(Comment::class, $id) ?? throw ApiProblem::notFound();
            $this->policy->findVisiblePost($comment->getPost()->getId()->toRfc4122(), $actor);
            $author = $comment->getAuthor();
            $comuna = $comment->getPost()->getComuna();
            if (!$comment->isPublished()) {
                throw ApiProblem::notFound();
            }
        } else {
            $author = $this->em->find(User::class, $id) ?? throw ApiProblem::notFound();
        }
        if (!$author->isActive() || $this->blocks->isBlockedEitherWay($actor, $author)) {
            throw ApiProblem::notFound();
        }
        if (null === $this->em->getRepository(ContentReport::class)->findOneBy(['reporter' => $actor, 'targetType' => $data->targetType, 'targetId' => $id])) {
            $this->em->persist(new ContentReport($comuna, $actor, $data->targetType, $id, TextNormalizer::normalize($data->reason), $this->clock->now()));
            $this->em->flush();
        }

        return null;
    }
}
