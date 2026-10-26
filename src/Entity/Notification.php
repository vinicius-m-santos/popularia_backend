<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** References, rather than snapshots of private content: access is checked on every read. */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_recipient_id', columns: ['recipient_id', 'id'])]
#[ORM\Index(name: 'idx_notification_recipient_read', columns: ['recipient_id', 'read_at'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_event', columns: ['recipient_id', 'type', 'event_key'])]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $recipient,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?User $actor,
        #[ORM\Column(length: 32, enumType: NotificationType::class)]
        private NotificationType $type,
        #[ORM\Column(length: 80)]
        private string $eventKey,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Post $post = null,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Comment $comment = null,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Comuna $comuna = null,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?ComunaJoinRequest $joinRequest = null,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?ComunaOwnershipTransfer $transfer = null,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function getPost(): ?Post
    {
        return $this->post;
    }

    public function getComment(): ?Comment
    {
        return $this->comment;
    }

    public function getComuna(): ?Comuna
    {
        return $this->comuna;
    }

    public function markRead(\DateTimeImmutable $now): void
    {
        $this->readAt ??= $now;
    }
}
