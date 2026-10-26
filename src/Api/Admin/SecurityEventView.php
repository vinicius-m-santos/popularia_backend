<?php

declare(strict_types=1);

namespace App\Api\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\SecurityEvent;

/**
 * Registro de segurança de uma conta, para investigação de incidentes.
 * Só ROLE_ADMIN. Exige um motivo, e cada consulta fica no AdminAuditLog.
 * Moderadores (da plataforma ou de comunas) não têm acesso.
 */
#[ApiResource(
    shortName: 'SecurityEvent',
    operations: [
        new GetCollection(
            uriTemplate: '/admin/security-events',
            security: "is_granted('ROLE_ADMIN')",
            provider: SecurityEventProvider::class,
            openapi: new Operation(
                tags: ['Administração'],
                summary: 'Eventos de segurança de uma conta (com IP). Consulta auditada.',
                parameters: [
                    new Parameter('userId', 'query', 'ID (UUID) da conta investigada.', required: true),
                    new Parameter('reason', 'query', 'Motivo da consulta (mínimo de 10 caracteres). Fica na auditoria.', required: true),
                ],
            ),
        ),
    ],
)]
final readonly class SecurityEventView
{
    /** @param array<string, scalar|null> $context */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $type,
        public ?string $userId,
        public ?string $sessionId,
        public ?string $ipAddress,
        public array $context,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public static function fromEvent(SecurityEvent $event): self
    {
        return new self(
            $event->getId()->toRfc4122(),
            $event->getType()->value,
            $event->getUser()?->getId()->toRfc4122(),
            $event->getSessionId()?->toRfc4122(),
            $event->getIpAddress(),
            $event->getContext(),
            $event->getOccurredAt(),
        );
    }
}
