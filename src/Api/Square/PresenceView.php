<?php

declare(strict_types=1);

namespace App\Api\Square;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/squares/{id}/presence',
            name: 'square_presence',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['id' => new Link(fromClass: SquareView::class, identifiers: ['id'])],
            provider: SquareProvider::class,
            openapi: new Operation(tags: ['Praças'], summary: 'Presença recente aproximada e até 12 pessoas; exclui bloqueios.'),
        ),
        new Put(
            uriTemplate: '/squares/{id}/presence',
            name: 'square_heartbeat',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['id' => new Link(fromClass: SquareView::class, identifiers: ['id'])],
            read: false,
            processor: SquareProcessor::class,
            deserialize: false,
            validate: false,
            status: 200,
            openapi: new Operation(tags: ['Praças'], summary: 'Renova presença; expira em 90s; no máximo uma escrita por 30s.'),
        ),
        new Put(
            uriTemplate: '/squares/{id}/read',
            name: 'square_read',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['id' => new Link(fromClass: SquareView::class, identifiers: ['id'])],
            read: false,
            processor: SquareProcessor::class,
            input: ReadInput::class,
            status: 204,
            output: false,
            openapi: new Operation(tags: ['Praças'], summary: 'Avança leitura até mensagem efetivamente vista no final da conversa.'),
        ),
    ],
)]
final readonly class PresenceView
{
    /** @param list<array{id: string, handle: string, displayName: string}> $people */
    public function __construct(public int $count, public array $people)
    {
    }
}
