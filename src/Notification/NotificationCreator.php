<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Comment;
use App\Entity\ComunaEvent;
use App\Entity\ComunaJoinRequest;
use App\Entity\ComunaOwnershipTransfer;
use App\Entity\Notification;
use App\Entity\Post;
use App\Entity\SquareMessage;
use App\Entity\User;
use App\Entity\UserFollow;
use App\Enum\JoinRequestStatus;
use App\Enum\NotificationType;
use App\Enum\OwnershipTransferStatus;
use App\Repository\ComunaMembershipRepository;
use App\Repository\UserBlockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Enqueues persistence in the caller's transaction, never flushes or delivers externally.
 * PushWorker consumes only committed notifications without changing callers.
 */
final readonly class NotificationCreator
{
    /** Aviso privado e genérico; nunca inclui denunciante, moderador ou nota interna. */
    public function contentRemoved(Post|Comment $content): void
    {
        if (!$content->getAuthor()->isActive()) {
            return;
        }
        // Uma notificação por conteúdo, inclusive quando denúncias distintas são decididas em paralelo.
        $this->em->getConnection()->executeStatement(
            'INSERT INTO notification (id, recipient_id, type, event_key, created_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT (recipient_id, type, event_key) DO NOTHING',
            [Uuid::v7()->toRfc4122(), $content->getAuthor()->getId()->toRfc4122(), $content instanceof Post ? NotificationType::PostRemoved->value : NotificationType::CommentRemoved->value, $content->getId()->toRfc4122(), $this->clock->now()->format(\DATE_ATOM)],
        );
    }

    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private UserBlockRepository $blocks,
        private ComunaMembershipRepository $memberships,
    ) {
    }

    public function squareReplied(SquareMessage $message): void
    {
        $parent = $message->replyTo;
        $comuna = $message->square->comuna;
        if (null === $parent || null === $comuna || (!$comuna->isPublic() && null === $this->memberships->findMembership($comuna, $parent->author))) {
            return;
        }
        $this->save($parent->author, $message->author, NotificationType::SquareReply, $message->id->toRfc4122(), squareMessage: $message);
    }

    public function followed(UserFollow $follow, User $actor, User $recipient): void
    {
        $this->save($recipient, $actor, NotificationType::Follow, $follow->getId()->toRfc4122());
    }

    public function commentSupported(Comment $comment, User $actor): void
    {
        // One notification per actor/comment, even after undo + redo.
        $this->save($comment->getAuthor(), $actor, NotificationType::CommentSupport, $comment->getId()->toRfc4122().':'.$actor->getId()->toRfc4122(), $comment);
    }

    public function commented(Comment $comment): void
    {
        $post = $comment->getPost();
        $actor = $comment->getAuthor();
        $parent = $comment->getParent();
        if (null !== $parent) {
            $this->save($parent->getAuthor(), $actor, NotificationType::Reply, $comment->getId()->toRfc4122(), $comment);
        }
        // Same event, same person: the specific reply takes precedence over the generic comment.
        if (null === $parent || !$parent->getAuthor()->getId()->equals($post->getAuthor()->getId())) {
            $this->save($post->getAuthor(), $actor, NotificationType::Comment, $comment->getId()->toRfc4122(), $comment);
        }
    }

    /**
     * Uma notificação por pessoa mencionada na publicação, mesmo que o nome apareça duas vezes.
     *
     * @param list<User> $mentioned
     */
    public function mentionedInPost(Post $post, array $mentioned): void
    {
        $seen = [];
        foreach ($mentioned as $user) {
            $id = $user->getId()->toRfc4122();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $this->save($user, $post->getAuthor(), NotificationType::MentionPost, $post->getId()->toRfc4122().':'.$id, post: $post);
        }
    }

    /**
     * Resposta e comentário na própria publicação têm precedência sobre a menção da mesma interação.
     *
     * @param list<User> $mentioned
     */
    public function mentionedInComment(Comment $comment, array $mentioned): void
    {
        $post = $comment->getPost();
        $parent = $comment->getParent();
        $postAuthorId = $post->getAuthor()->getId()->toRfc4122();
        $parentAuthorId = $parent?->getAuthor()->getId()->toRfc4122();
        $commentNotifiesPostAuthor = null === $parentAuthorId || $parentAuthorId !== $postAuthorId;
        $seen = [];
        foreach ($mentioned as $user) {
            $id = $user->getId()->toRfc4122();
            if (isset($seen[$id]) || $id === $parentAuthorId || ($commentNotifiesPostAuthor && $id === $postAuthorId)) {
                continue;
            }
            $seen[$id] = true;
            $this->save($user, $comment->getAuthor(), NotificationType::MentionComment, $comment->getId()->toRfc4122().':'.$id, $comment);
        }
    }

    public function joinRequested(ComunaJoinRequest $request): void
    {
        foreach ($this->memberships->findModeration($request->getComuna()) as $membership) {
            $this->save($membership->getUser(), $request->getUser(), NotificationType::JoinRequested, $request->getId()->toRfc4122(), request: $request);
        }
    }

    public function joinDecided(ComunaJoinRequest $request, User $actor): void
    {
        $type = JoinRequestStatus::Approved === $request->getStatus() ? NotificationType::JoinApproved : NotificationType::JoinRejected;
        $this->save($request->getUser(), $actor, $type, $request->getId()->toRfc4122(), request: $request);
    }

    /** Aviso de mudança ou cancelamento. A chave inclui a revisão, então repetir a mesma operação não duplica. */
    public function eventNotice(ComunaEvent $event, User $recipient, User $actor, NotificationType $type): void
    {
        $this->save($recipient, $actor, $type, $event->getId()->toRfc4122().':rev:'.$event->getRevision(), event: $event);
    }

    /** Um lembrete por início anunciado. Remarcar muda o timestamp e permite um novo; retry do mesmo início não. */
    public function eventReminder(ComunaEvent $event, User $recipient): void
    {
        $this->save($recipient, null, NotificationType::EventReminder, $event->getId()->toRfc4122().':remind:'.$event->getStartsAt()->getTimestamp(), event: $event);
    }

    public function ownershipChanged(ComunaOwnershipTransfer $transfer): void
    {
        [$type, $actor, $recipients] = match ($transfer->getStatus()) {
            OwnershipTransferStatus::Pending => [NotificationType::OwnershipProposed, $transfer->getFromUser(), [$transfer->getToUser()]],
            OwnershipTransferStatus::Accepted => [NotificationType::OwnershipAccepted, $transfer->getToUser(), [$transfer->getFromUser(), $transfer->getToUser()]],
            OwnershipTransferStatus::Declined => [NotificationType::OwnershipDeclined, $transfer->getToUser(), [$transfer->getFromUser()]],
            OwnershipTransferStatus::Cancelled => [NotificationType::OwnershipCancelled, $transfer->getFromUser(), [$transfer->getToUser()]],
        };
        foreach ($recipients as $recipient) {
            // The new owner also receives confirmation, without representing it as a self-action.
            $this->save($recipient, $recipient->getId()->equals($actor->getId()) ? null : $actor, $type, $transfer->getId()->toRfc4122(), transfer: $transfer);
        }
    }

    private function save(
        User $recipient,
        ?User $actor,
        NotificationType $type,
        string $eventKey,
        ?Comment $comment = null,
        ?ComunaJoinRequest $request = null,
        ?ComunaOwnershipTransfer $transfer = null,
        ?SquareMessage $squareMessage = null,
        ?Post $post = null,
        ?ComunaEvent $event = null,
    ): void {
        if (!$recipient->isActive() || (null !== $actor && ($recipient->getId()->equals($actor->getId()) || $this->blocks->isBlockedEitherWay($recipient, $actor)))) {
            return;
        }
        $criteria = ['recipient' => $recipient, 'type' => $type, 'eventKey' => $eventKey];
        if (null !== $this->em->getRepository(Notification::class)->findOneBy($criteria)) {
            return;
        }
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof Notification && $pending->matchesEvent($recipient, $type, $eventKey)) {
                return;
            }
        }
        $linkedPost = $comment?->getPost() ?? $post;
        $this->em->persist(new Notification(
            $recipient,
            $actor,
            $type,
            $eventKey,
            $this->clock->now(),
            $linkedPost,
            $comment,
            $linkedPost?->getComuna() ?? $request?->getComuna() ?? $transfer?->getComuna() ?? $squareMessage?->square->comuna ?? $event?->getComuna(),
            $request,
            $transfer,
            $squareMessage,
            $event,
        ));
    }
}
