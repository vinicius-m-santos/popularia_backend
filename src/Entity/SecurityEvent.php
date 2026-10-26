<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SecurityEventType;
use App\Repository\SecurityEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Registro de segurança: o quê, quem, qual sessão, de qual IP e quando (UTC).
 *
 * Diferente dos logs operacionais (Monolog), este registro serve para investigar incidentes.
 * Só administradores consultam, toda consulta é auditada (AdminAuditLog) e os eventos são
 * apagados depois de SECURITY_EVENT_RETENTION_DAYS, exceto os preservados por EvidenceHold.
 * Nunca guarda senhas, tokens, códigos ou o e-mail digitado.
 */
#[ORM\Entity(repositoryClass: SecurityEventRepository::class)]
#[ORM\Table(name: 'security_event')]
#[ORM\Index(name: 'idx_security_event_user', columns: ['user_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_security_event_occurred', columns: ['occurred_at'])]
class SecurityEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 50, enumType: SecurityEventType::class)]
    private SecurityEventType $type;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    /** Sem chave estrangeira: o evento continua válido depois que a sessão é limpa. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $sessionId;

    /** IP visto pelo servidor (REMOTE_ADDR ou X-Forwarded-For de proxy confiável). */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress;

    /** @var array<string, scalar|null> Detalhes curtos e não sensíveis, como o motivo de uma falha. */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $context;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** @param array<string, scalar|null> $context */
    public function __construct(SecurityEventType $type, ?User $user, ?Uuid $sessionId, ?string $ipAddress, array $context, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->type = $type;
        $this->user = $user;
        $this->sessionId = $sessionId;
        $this->ipAddress = $ipAddress;
        $this->context = $context;
        $this->occurredAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): SecurityEventType
    {
        return $this->type;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getSessionId(): ?Uuid
    {
        return $this->sessionId;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /** @return array<string, scalar|null> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
