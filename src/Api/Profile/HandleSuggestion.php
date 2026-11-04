<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;

/** Sugere um @ legível e livre para a etapa de cadastro. */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/handles/suggestion',
            provider: HandleSuggestionProvider::class,
            openapi: new Operation(
                tags: ['Perfis'],
                summary: 'Sugere um nome de usuário legível e disponível para o cadastro.',
                description: 'Limite de taxa por IP.',
            ),
        ),
    ],
)]
final readonly class HandleSuggestion
{
    public function __construct(public string $handle)
    {
    }
}
