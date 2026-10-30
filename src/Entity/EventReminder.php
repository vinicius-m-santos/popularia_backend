<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventReminderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Lembrete durável no PostgreSQL. sent_at impede retry duplicado.
 * sent_for_starts_at diz para qual início aquele envio valeu: se a data muda, dá para recalcular.
 */
#[ORM\Entity(repositoryClass: EventReminderRepository::class)]
#[ORM\Table(name: 'event_reminder')]
#[ORM\UniqueConstraint(name: 'uniq_event_reminder', columns: ['event_id', 'user_id'])]
#[ORM\Index(name: 'idx_event_reminder_due', columns: ['remind_at', 'id'], options: ['where' => '(sent_at IS NULL)'])]
class EventReminder
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentForStartsAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ComunaEvent $event,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $remindAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function reschedule(\DateTimeImmutable $remindAt): void
    {
        $this->remindAt = $remindAt;
        $this->sentAt = null;
        $this->sentForStartsAt = null;
    }

    public function markSent(\DateTimeImmutable $now, \DateTimeImmutable $startsAt): void
    {
        $this->sentAt = $now;
        $this->sentForStartsAt = $startsAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEvent(): ComunaEvent
    {
        return $this->event;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRemindAt(): \DateTimeImmutable
    {
        return $this->remindAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getSentForStartsAt(): ?\DateTimeImmutable
    {
        return $this->sentForStartsAt;
    }

    public function wasSentFor(\DateTimeImmutable $startsAt): bool
    {
        return null !== $this->sentAt && null !== $this->sentForStartsAt && $this->sentForStartsAt->getTimestamp() === $startsAt->getTimestamp();
    }
}
