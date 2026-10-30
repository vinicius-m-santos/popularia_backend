<?php

declare(strict_types=1);

namespace App\Api\Moderation;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Api\Comuna\ComunaView;

#[ApiResource(operations: [
    new Get(
        uriTemplate: '/comunas/{slug}/content-reports',
        name: 'comuna_content_reports',
        security: "is_granted('ROLE_USER')",
        uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
        provider: ReportQueueProvider::class,
        openapi: new Operation(
            tags: ['Moderação'],
            summary: 'Fila de denúncias de publicações e comentários desta comuna. Só moderação da própria comuna. Pendentes mais antigas primeiro.',
            parameters: [
                new Parameter('bucket', 'query', 'open (pendentes) ou closed (resolvidas). Padrão open.', schema: ['type' => 'string', 'enum' => ['open', 'closed']]),
                new Parameter('cursor', 'query', 'nextCursor da página anterior.'),
                new Parameter('limit', 'query', '1..50; padrão 20.', schema: ['type' => 'integer']),
            ],
        ),
    ),
    new Get(
        uriTemplate: '/moderation/reports',
        name: 'platform_reports',
        security: "is_granted('ROLE_PLATFORM_MODERATOR')",
        provider: ReportQueueProvider::class,
        openapi: new Operation(
            tags: ['Moderação'],
            summary: 'Fila da plataforma: denúncias de perfil, comuna, conteúdo sem comuna e denúncias escaladas. Pendentes mais antigas primeiro.',
            parameters: [
                new Parameter('bucket', 'query', 'open ou closed. Padrão open.', schema: ['type' => 'string', 'enum' => ['open', 'closed']]),
                new Parameter('cursor', 'query', 'nextCursor da página anterior.'),
                new Parameter('limit', 'query', '1..50; padrão 20.', schema: ['type' => 'integer']),
            ],
        ),
    ),
])]
final readonly class ReportQueue
{
    /** @param list<ReportItem> $items */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
