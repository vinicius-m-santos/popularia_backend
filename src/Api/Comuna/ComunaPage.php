<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;

/** Página de comunas (todas, ou as da pessoa logada). */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/comunas',
            provider: ComunaPageProvider::class,
            openapi: new Operation(
                tags: ['Comunas'],
                summary: 'Lista as comunas, com paginação por cursor.',
                parameters: [
                    new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'Itens por página (1 a 50, padrão 20).', schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/me/comunas',
            security: "is_granted('ROLE_USER')",
            provider: MyComunasProvider::class,
            openapi: new Operation(
                tags: ['Conta'],
                summary: 'Comunas de que você participa, com seu papel em cada uma.',
                parameters: [
                    new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'Itens por página (1 a 50, padrão 20).', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
)]
final readonly class ComunaPage
{
    /** @param list<ComunaView> $items */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $items,
        /** Passe em ?cursor= para a próxima página; null quando não há mais. */
        public ?string $nextCursor,
    ) {
    }
}
