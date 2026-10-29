<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Durable outbox. No token or content snapshots; the worker revalidates access. */
#[ORM\Entity]
#[ORM\Table(name: 'push_delivery')]
#[ORM\UniqueConstraint(name: 'uniq_push_delivery', columns: ['notification_id', 'device_id'])]
#[ORM\Index(name: 'idx_push_delivery_due', columns: ['status', 'available_at'])]
#[ORM\Index(name: 'idx_push_delivery_device', columns: ['device_id'])]
class PushDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(length: 16)]
    public string $status = 'pending';

    #[ORM\Column]
    public int $attempts = 0;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $ticket = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $errorCode = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Notification $notification,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public PushDevice $device,
        #[ORM\Column(length: 64)]
        public string $tokenHash,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $availableAt,
    ) {
        $this->id = Uuid::v7();
    }
}
