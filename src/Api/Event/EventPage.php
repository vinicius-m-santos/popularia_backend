<?php

declare(strict_types=1);

namespace App\Api\Event;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Api\Comuna\ComunaView;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/comunas/{slug}/events',
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            provider: ComunaEventsProvider::class,
            openapi: new Operation(
                tags: ['Eventos'],
                summary: 'Eventos da comuna.',
                description: 'when=upcoming (padrão) em ordem crescente de início; when=past, mais recentes primeiro. Cancelados continuam na lista. Comuna privada: 403 /errors/comuna-private.',
                parameters: [
                    new Parameter('when', 'query', 'upcoming ou past.', schema: ['type' => 'string', 'enum' => ['upcoming', 'past']]),
                    new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'De 1 a 50. Padrão 20.', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
)]
final readonly class EventPage
{
    /** @param list<EventView> $items */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
