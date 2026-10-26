<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;

/** Pedidos de entrada em aberto, do mais antigo para o mais novo. */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/comunas/{slug}/join-requests',
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            security: "is_granted('ROLE_USER')",
            provider: JoinRequestPageProvider::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Pedidos de entrada em aberto (proprietário ou moderação da comuna).',
                parameters: [
                    new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'Itens por página (1 a 50, padrão 20).', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
)]
final readonly class JoinRequestPage
{
    /** @param list<JoinRequestView> $items */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
