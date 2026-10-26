<?php

declare(strict_types=1);

namespace App\Api\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\EvidenceHold;

/**
 * Preservação de evidências: impede que a rotina de retenção apague os eventos de segurança
 * de uma conta durante uma investigação. Só ROLE_ADMIN; criação e liberação são auditadas.
 */
#[ApiResource(
    shortName: 'EvidenceHold',
    operations: [
        new GetCollection(
            uriTemplate: '/admin/evidence-holds',
            security: "is_granted('ROLE_ADMIN')",
            provider: EvidenceHoldProvider::class,
            openapi: new Operation(tags: ['Administração'], summary: 'Lista as preservações de evidências (as mais recentes primeiro).'),
        ),
        new Post(
            uriTemplate: '/admin/evidence-holds',
            status: 201,
            security: "is_granted('ROLE_ADMIN')",
            input: EvidenceHoldInput::class,
            processor: EvidenceHoldCreateProcessor::class,
            openapi: new Operation(tags: ['Administração'], summary: 'Preserva os eventos de segurança de uma conta, com justificativa.'),
        ),
        new Post(
            uriTemplate: '/admin/evidence-holds/{id}/release',
            status: 200,
            security: "is_granted('ROLE_ADMIN')",
            input: EvidenceHoldRelease::class,
            read: false,
            processor: EvidenceHoldReleaseProcessor::class,
            openapi: new Operation(tags: ['Administração'], summary: 'Encerra uma preservação; os eventos voltam à retenção normal.'),
        ),
    ],
)]
final readonly class EvidenceHoldView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $subjectId,
        public string $reason,
        public string $createdById,
        public \DateTimeImmutable $createdAt,
        public bool $active,
        public ?\DateTimeImmutable $releasedAt,
    ) {
    }

    public static function fromHold(EvidenceHold $hold): self
    {
        return new self(
            $hold->getId()->toRfc4122(),
            $hold->getSubject()->getId()->toRfc4122(),
            $hold->getReason(),
            $hold->getCreatedBy()->getId()->toRfc4122(),
            $hold->getCreatedAt(),
            $hold->isActive(),
            $hold->getReleasedAt(),
        );
    }
}
