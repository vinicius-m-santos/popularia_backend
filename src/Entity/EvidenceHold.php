<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EvidenceHoldRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Preservação de evidências de um incidente: enquanto estiver ativa, os eventos de segurança
 * da conta investigada não são apagados pela rotina de retenção. Exige justificativa e
 * responsável; criação e liberação ficam no AdminAuditLog.
 */
#[ORM\Entity(repositoryClass: EvidenceHoldRepository::class)]
#[ORM\Table(name: 'evidence_hold')]
#[ORM\Index(name: 'idx_evidence_hold_subject', columns: ['subject_id', 'released_at'])]
class EvidenceHold
{
    public const int REASON_MIN_LENGTH = 10;
    public const int REASON_MAX_LENGTH = 1000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $releasedBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $releaseReason = null;

    public function __construct(User $subject, string $reason, User $createdBy, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->subject = $subject;
        $this->reason = $reason;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSubject(): User
    {
        return $this->subject;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        return null === $this->releasedAt;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function release(User $by, string $reason, \DateTimeImmutable $now): void
    {
        $this->releasedBy = $by;
        $this->releaseReason = $reason;
        $this->releasedAt = $now;
    }
}
