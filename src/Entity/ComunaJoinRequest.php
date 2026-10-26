<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\JoinRequestStatus;
use App\Repository\ComunaJoinRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Pedido para entrar numa comuna privada. A participação só existe depois da aprovação por
 * quem é proprietário ou modera a comuna. No máximo um pedido em aberto por pessoa e comuna
 * (índice parcial no banco).
 */
#[ORM\Entity(repositoryClass: ComunaJoinRequestRepository::class)]
#[ORM\Table(name: 'comuna_join_request')]
#[ORM\Index(name: 'idx_comuna_join_request_comuna', columns: ['comuna_id', 'status', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_comuna_join_request_pending', columns: ['comuna_id', 'user_id'], options: ['where' => "status = 'pending'"])]
class ComunaJoinRequest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: JoinRequestStatus::class)]
    private JoinRequestStatus $status = JoinRequestStatus::Pending;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Comuna $comuna,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getComuna(): Comuna
    {
        return $this->comuna;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): JoinRequestStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return JoinRequestStatus::Pending === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function close(JoinRequestStatus $status, \DateTimeImmutable $now): void
    {
        if (JoinRequestStatus::Pending === $status) {
            throw new \InvalidArgumentException('Um pedido só pode ser fechado com uma decisão.');
        }
        $this->status = $status;
        $this->decidedAt = $now;
    }
}
