<?php

declare(strict_types=1);

namespace App\Api\Comment;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Content\ContentPolicy;
use App\Content\TextNormalizer;
use App\Entity\Comment;
use App\Entity\CommentSupport;
use App\Gamification\GamificationAction;
use App\Gamification\GamificationService;
use App\Mention\MentionBinder;
use App\Notification\NotificationCreator;
use App\Repository\CommentRepository;
use App\Repository\PostRepository;
use App\Repository\UserBlockRepository;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/** @implements ProcessorInterface<CommentInput, CommentView> */
final readonly class CommentCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ContentPolicy $policy,
        private \App\Content\RemovedContentFilter $filter,
        private CommentRepository $comments,
        private PostRepository $posts,
        private UserBlockRepository $blocks,
        private RateLimitGuard $rateLimit,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private NotificationCreator $notifications,
        private MentionBinder $mentions,
        private GamificationService $gamification,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommentView
    {
        $author = $this->currentUser->get();
        $post = $this->policy->findVisiblePost((string) ($uriVariables['id'] ?? ''), $author);
        if (null !== $problem = $this->policy->whyCannotComment($post, $author)) {
            throw $problem;
        }

        $parent = null;
        if (null !== $data->parentId) {
            $parent = $this->comments->find(Uuid::fromString($data->parentId));
            // O pai precisa existir, ser desta publicação, estar publicado e não ser de quem tem bloqueio com você.
            if (null === $parent
                || !$parent->getPost()->getId()->equals($post->getId())
                || !$parent->isPublished()
                || $this->blocks->isBlockedEitherWay($author, $parent->getAuthor())) {
                throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('Não é possível responder a este comentário.', null, [], $data, 'parentId', $data->parentId)]));
            }
        }
        $this->rateLimit->consume('content_creation', $author->getUserIdentifier());

        $this->filter->assertText($data->body, $post->getComuna());
        $body = TextNormalizer::normalize($data->body);
        $now = $this->clock->now();
        $comment = new Comment($post, $parent, $author, $body, $now);
        $mentions = $this->mentions->attach($body, $data->mentions, $comment);
        $this->em->wrapInTransaction(function () use ($comment, $post, $author, $mentions, $now): void {
            $this->gamification->prepare($author, $now);
            $this->em->persist($comment);
            foreach ($mentions as $mention) {
                $this->em->persist($mention);
            }
            // Todo comentário começa com o apoio persistente de quem escreveu.
            $this->em->persist(new CommentSupport($comment, $author));
            $this->notifications->commented($comment);
            $this->notifications->mentionedInComment($comment, array_map(static fn ($mention) => $mention->getMentionedUser(), $mentions));
            $this->em->flush();
            $this->posts->incrementCommentCount($post);
            $this->gamification->record($author, GamificationAction::DiscussionParticipated, $post->getId(), $now);
        });

        return CommentView::fromComment($comment, false, $author->getId()->toRfc4122(), false, 1, true, $mentions);
    }
}
