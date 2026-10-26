<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;

/** Se um @ pode ser usado no cadastro. O @ é público, então isso não revela dado privado. */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/handles/{handle}/availability',
            provider: HandleAvailabilityProvider::class,
            openapi: new Operation(
                tags: ['Perfis'],
                summary: 'Diz se um nome de usuário está livre e tem formato válido.',
                description: 'Limite de taxa por IP.',
            ),
        ),
    ],
)]
final readonly class HandleAvailability
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $handle,
        public bool $available,
    ) {
    }
}
