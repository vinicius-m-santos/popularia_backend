<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;

/**
 * Se um nome de comuna está livre, e o endereço (slug) que ele geraria. É só uma prévia para o
 * formulário: POST /comunas confere de novo e o banco garante a unicidade do slug.
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/comuna-names/availability',
            security: "is_granted('ROLE_USER')",
            provider: ComunaNameAvailabilityProvider::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Diz se um nome de comuna está livre.',
                description: 'Limite de taxa por conta.',
                parameters: [new Parameter('name', 'query', 'Nome pretendido.', required: true)],
            ),
        ),
    ],
)]
final readonly class ComunaNameAvailability
{
    public function __construct(
        public string $name,
        public string $slug,
        public bool $available,
        /** Por que o nome não serve (null quando está livre). */
        public ?string $message,
    ) {
    }
}
