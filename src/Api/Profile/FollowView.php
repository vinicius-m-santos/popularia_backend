<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/users/{handle}/follow',
            uriVariables: ['handle' => new Link(fromClass: PublicProfile::class, identifiers: ['handle'])],
            security: "is_granted('ROLE_USER')",
            provider: FollowProvider::class,
            openapi: new Operation(tags: ['Perfis'], summary: 'Se sigo este perfil.'),
        ),
        new Put(
            uriTemplate: '/users/{handle}/follow',
            uriVariables: ['handle' => new Link(fromClass: PublicProfile::class, identifiers: ['handle'])],
            status: 200,
            read: false,
            deserialize: false,
            validate: false,
            security: "is_granted('ROLE_USER')",
            processor: FollowProcessor::class,
            openapi: new Operation(tags: ['Perfis'], summary: 'Segue uma pessoa (idempotente; sem seguir a si mesmo ou com bloqueio).'),
        ),
        new Delete(
            uriTemplate: '/users/{handle}/follow',
            uriVariables: ['handle' => new Link(fromClass: PublicProfile::class, identifiers: ['handle'])],
            status: 204,
            read: false,
            deserialize: false,
            validate: false,
            output: false,
            security: "is_granted('ROLE_USER')",
            processor: FollowProcessor::class,
            openapi: new Operation(tags: ['Perfis'], summary: 'Deixa de seguir uma pessoa (idempotente).'),
        ),
    ],
)]
final readonly class FollowView
{
    public function __construct(public bool $following, public bool $requested = false)
    {
    }
}
