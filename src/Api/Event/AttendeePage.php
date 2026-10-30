<?php

declare(strict_types=1);

namespace App\Api\Event;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Api\Profile\AuthorView;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/events/{id}/attendees',
            requirements: ['id' => '[0-9a-fA-F-]{36}'],
            security: "is_granted('ROLE_USER')",
            provider: AttendeePageProvider::class,
            openapi: new Operation(
                tags: ['Eventos'],
                summary: 'Quem respondeu Vou ou Talvez.',
                description: 'status=going ou status=maybe. Não vou não é listado. Bloqueio e conta inativa ficam de fora. Comuna privada sem participação: 404.',
                parameters: [
                    new Parameter('status', 'query', 'going ou maybe.', required: true, schema: ['type' => 'string', 'enum' => ['going', 'maybe']]),
                    new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'De 1 a 50. Padrão 20.', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
)]
final readonly class AttendeePage
{
    /** @param list<AuthorView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
