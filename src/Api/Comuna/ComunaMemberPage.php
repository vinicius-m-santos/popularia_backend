<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;

/** Página de membros de uma comuna, na ordem de entrada. */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/comunas/{slug}/members',
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            security: "is_granted('ROLE_USER')",
            provider: ComunaMemberPageProvider::class,
            openapi: new Operation(
                tags: ['Comunas'],
                summary: 'Membros da comuna (só para quem participa).',
                description: 'Participar de uma comuna pode revelar opiniões: a lista é só para membros (403 /errors/members-only). Proprietário e moderação aparecem para todos em GET /comunas/{slug}.',
                parameters: [
                    new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'Itens por página (1 a 50, padrão 20).', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
)]
final readonly class ComunaMemberPage
{
    /** @param list<ComunaMemberView> $items */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
