<?php

declare(strict_types=1);

namespace App\Api\Square;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Api\Comuna\ComunaView;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/comunas/{slug}/reports',
            name: 'square_reports',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            provider: SquareProvider::class,
            openapi: new Operation(tags: ['Praças'], summary: 'Fila paginada de denúncias da comuna; só moderação.', parameters: [new Parameter('cursor', 'query', 'nextCursor da página anterior.'), new Parameter('limit', 'query', '1..50; padrão 20.', schema: ['type' => 'integer'])]),
        ),
    ],
)]
final readonly class ReportPage
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(public array $items, public ?string $nextCursor)
    {
    }
}
