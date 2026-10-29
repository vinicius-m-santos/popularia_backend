<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Api\Comuna\ComunaView;
use App\Api\Profile\PublicProfile;

/** Página de publicações (feed ou comuna), da mais nova para a mais antiga. */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/users/{handle}/posts',
            uriVariables: ['handle' => new Link(fromClass: PublicProfile::class, identifiers: ['handle'])],
            provider: ProfilePostsProvider::class,
            openapi: new Operation(tags: ['Perfis'], summary: 'Publicações visíveis de uma pessoa, com paginação por cursor.'),
        ),
        new Get(
            uriTemplate: '/feed',
            security: "is_granted('ROLE_USER')",
            provider: FeedProvider::class,
            openapi: new Operation(
                tags: ['Publicações'],
                summary: 'Feed da pessoa logada.',
                description: 'tab=para-voce (padrão): comunas públicas, comunas de que participa e publicações em perfis. tab=minhas: só comunas de que participa. tab=seguindo: só publicações de pessoas com follow aprovado; pedido pendente não inclui, e comuna privada continua exigindo participação. Ordem cronológica. Sem publicações de quem tem bloqueio com você.',
                parameters: [
                    new Parameter('tab', 'query', 'para-voce, minhas ou seguindo.', schema: ['type' => 'string', 'enum' => ['para-voce', 'minhas', 'seguindo']]),
                    new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'Itens por página (1 a 50, padrão 20).', schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/comunas/{slug}/posts',
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            provider: ComunaPostsProvider::class,
            openapi: new Operation(
                tags: ['Comunas'],
                summary: 'Publicações de uma comuna.',
                description: 'Comuna privada sem participação: 403 /errors/comuna-private.',
                parameters: [
                    new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'Itens por página (1 a 50, padrão 20).', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
)]
final readonly class PostPage
{
    /** @param list<PostView> $items */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $items,
        /** Passe em ?cursor= para a próxima página; null quando não há mais. */
        public ?string $nextCursor,
    ) {
    }
}
