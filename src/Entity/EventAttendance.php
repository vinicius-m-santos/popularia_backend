<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AttendanceStatus;
use App\Repository\EventAttendanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Uma linha por pessoa e evento. A resposta nova substitui a anterior. */
#[ORM\Entity(repositoryClass: EventAttendanceRepository::class)]
#[ORM\Table(name: 'event_attendance')]
#[ORM\UniqueConstraint(name: 'uniq_event_attendance', columns: ['event_id', 'user_id'])]
#[ORM\Index(name: 'idx_event_attendance_status', columns: ['event_id', 'status', 'id'])]
class EventAttendance
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ComunaEvent $event,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 20, enumType: AttendanceStatus::class)]
        private AttendanceStatus $status,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $updatedAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function replace(AttendanceStatus $status, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->updatedAt = $now;
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

    public function getStatus(): AttendanceStatus
    {
        return $this->status;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
