<?php

declare(strict_types=1);

namespace App\Api\Moderation;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;

/** Denúncia visível só para quem pode decidi-la. Não inclui quem denunciou. */
#[ApiResource(operations: [
    new Get(
        uriTemplate: '/moderation/reports/{id}',
        security: "is_granted('ROLE_USER')",
        provider: ReportItemProvider::class,
        openapi: new Operation(tags: ['Moderação'], summary: 'Detalhe de uma denúncia que a pessoa pode revisar. Sem o denunciante.'),
    ),
    new Post(
        uriTemplate: '/moderation/reports/{id}/decision',
        status: 204,
        input: ReportDecision::class,
        output: false,
        processor: ReportDecisionProcessor::class,
        security: "is_granted('ROLE_USER')",
        openapi: new Operation(tags: ['Moderação'], summary: 'Decide uma denúncia: dispensar, remover, restaurar, escalar, suspender ou reativar. A autorização é conferida de novo no servidor.'),
    ),
])]
final readonly class ReportItem
{
    /**
     * @param list<string> $actions
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $targetType,
        public string $targetId,
        public ?string $reasonCode,
        public string $reasonLabel,
        public ?string $description,
        public ?string $snapshot,
        public string $status,
        public bool $escalated,
        public string $scope,
        public \DateTimeImmutable $createdAt,
        public bool $targetAvailable,
        public ?string $targetLabel,
        public ?string $comunaSlug,
        public ?ReportSubject $targetAuthor,
        public array $actions,
    ) {
    }
}
