<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OwnershipTransferStatus;
use App\Repository\ComunaOwnershipTransferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Proposta de passar a propriedade de uma comuna para outro membro. Até o aceite, nada muda:
 * quem propôs continua proprietário. No máximo uma proposta em aberto por comuna (índice parcial).
 */
#[ORM\Entity(repositoryClass: ComunaOwnershipTransferRepository::class)]
#[ORM\Table(name: 'comuna_ownership_transfer')]
#[ORM\Index(name: 'idx_comuna_ownership_transfer_to', columns: ['to_user_id', 'status'])]
#[ORM\UniqueConstraint(name: 'uniq_comuna_ownership_transfer_pending', columns: ['comuna_id'], options: ['where' => "status = 'pending'"])]
class ComunaOwnershipTransfer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: OwnershipTransferStatus::class)]
    private OwnershipTransferStatus $status = OwnershipTransferStatus::Pending;

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
        private User $fromUser,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $toUser,
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

    public function getFromUser(): User
    {
        return $this->fromUser;
    }

    public function getToUser(): User
    {
        return $this->toUser;
    }

    public function getStatus(): OwnershipTransferStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return OwnershipTransferStatus::Pending === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function close(OwnershipTransferStatus $status, \DateTimeImmutable $now): void
    {
        if (OwnershipTransferStatus::Pending === $status) {
            throw new \InvalidArgumentException('Uma proposta só pode ser fechada com uma decisão.');
        }
        $this->status = $status;
        $this->decidedAt = $now;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }
}
