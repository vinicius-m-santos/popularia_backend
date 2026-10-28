<?php

declare(strict_types=1);

namespace App\Api\Square;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/squares',
            name: 'square_list',
            security: "is_granted('ROLE_USER')",
            provider: SquareProvider::class,
            openapi: new Operation(tags: ['Praças'], summary: 'Suas Praças ou públicas descobríveis; nunca retorna privadas de terceiros.', parameters: [new Parameter('cursor', 'query', 'nextCursor da página anterior.'), new Parameter('limit', 'query', '1..50; padrão 20.', schema: ['type' => 'integer']), new Parameter('section', 'query', 'mine ou discover; padrão discover.'), new Parameter('q', 'query', 'Busca por nome, descrição ou lugar da comuna.')]),
        ),
    ],
)]
final readonly class SquarePage
{
    /** @param list<SquareView> $items */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
