<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;

/**
 * Seguidores e seguindo de um perfil. Perfil privado: só a própria pessoa e
 * seguidores já aprovados. Bloqueio nos dois sentidos responde 404, como o perfil.
 */
#[ApiResource(
    shortName: 'FollowList',
    operations: [
        new Get(
            uriTemplate: '/users/{handle}/followers',
            uriVariables: ['handle' => new Link(fromClass: PublicProfile::class, identifiers: ['handle'])],
            security: "is_granted('ROLE_USER')",
            provider: FollowListProvider::class,
            name: 'user_followers',
            openapi: new Operation(
                tags: ['Perfis'],
                summary: 'Seguidores aprovados de um perfil.',
                description: 'Paginado por cursor (id da relação, do mais novo). Cada item traz a relação de quem consulta, sem pedido extra. Perfil privado sem follow aprovado: 403 /errors/follow-list-private.',
                parameters: [
                    new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'Itens por página (1 a 50, padrão 20).', schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/users/{handle}/following',
            uriVariables: ['handle' => new Link(fromClass: PublicProfile::class, identifiers: ['handle'])],
            security: "is_granted('ROLE_USER')",
            provider: FollowListProvider::class,
            name: 'user_following',
            openapi: new Operation(
                tags: ['Perfis'],
                summary: 'Pessoas que este perfil segue, com follow aprovado.',
                description: 'Mesma paginação e a mesma regra de perfil privado de /followers. Pedidos pendentes não entram.',
                parameters: [
                    new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'Itens por página (1 a 50, padrão 20).', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    cacheHeaders: ['max_age' => 0, 'shared_max_age' => 0, 'public' => false],
)]
final readonly class FollowListPage
{
    /** @param list<FollowListPerson> $items */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
