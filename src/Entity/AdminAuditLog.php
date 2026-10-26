<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminAuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Auditoria de ações administrativas e de consultas a dados sensíveis: quem, o quê, sobre
 * quem, por quê e quando. Só recebe inserções: um gatilho no banco recusa UPDATE e DELETE
 * (migration Version20261026000000).
 */
#[ORM\Entity(repositoryClass: AdminAuditLogRepository::class, readOnly: true)]
#[ORM\Table(name: 'admin_audit_log')]
#[ORM\Index(name: 'idx_admin_audit_log_target', columns: ['target_user_id', 'occurred_at'])]
class AdminAuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Vazio quando a ação vem de um comando de console (actorLabel = "console"). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $actor;

    #[ORM\Column(length: 40)]
    private string $actorLabel;

    #[ORM\Column(length: 64)]
    private string $action;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $targetUserId;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $details;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** @param array<string, scalar|null> $details */
    public function __construct(?User $actor, string $actorLabel, string $action, ?Uuid $targetUserId, string $reason, array $details, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->actor = $actor;
        $this->actorLabel = $actorLabel;
        $this->action = $action;
        $this->targetUserId = $targetUserId;
        $this->reason = $reason;
        $this->details = $details;
        $this->occurredAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorLabel(): string
    {
        return $this->actorLabel;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getTargetUserId(): ?Uuid
    {
        return $this->targetUserId;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /** @return array<string, scalar|null> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
