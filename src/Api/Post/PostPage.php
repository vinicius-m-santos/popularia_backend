<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Api\Comuna\ComunaView;
use App\Api\Comuna\PinnedPostProcessor;
use App\Api\Comuna\PinnedPostsProvider;
use App\Api\Comuna\PinPositionInput;
use App\Api\Hashtag\HashtagPostsProvider;
use App\Api\Profile\PublicProfile;

/** Página de publicações (feed ou comuna), da mais nova para a mais antiga. */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/topics/{slug}/posts',
            provider: HashtagPostsProvider::class,
            openapi: new Operation(tags: ['Tópicos'], summary: 'Publicações visíveis de uma hashtag, da mais recente para a mais antiga.', parameters: [
                new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                new Parameter('limit', 'query', 'Itens por página (1 a 50, padrão 20).', schema: ['type' => 'integer']),
            ]),
        ),
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
        new Get(
            uriTemplate: '/comunas/{slug}/pinned-posts',
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            provider: PinnedPostsProvider::class,
            openapi: new Operation(
                tags: ['Comunas'],
                summary: 'Publicações fixadas da comuna, no máximo 3, na ordem definida pela moderação.',
                description: 'A mesma representação de publicação do restante da API. Comuna privada sem participação: 403 /errors/comuna-private. Publicação apagada, removida, de conta suspensa ou oculta por bloqueio não entra. nextCursor é sempre null.',
            ),
        ),
        new Put(
            uriTemplate: '/comunas/{slug}/pinned-posts/{postId}',
            name: 'comuna_pin_post',
            read: false,
            deserialize: false,
            validate: false,
            input: false,
            status: 200,
            security: "is_granted('ROLE_USER')",
            processor: PinnedPostProcessor::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Fixa uma publicação desta comuna. Idempotente.',
                description: 'Só proprietário ou moderação (403 /errors/not-comuna-moderator). O pin novo entra em primeiro. Já fixada: a posição não muda. Quatro fixadas: 409 /errors/comuna-pin-limit, sem remover outra. Publicação de outro lugar, apagada, removida ou invisível: 404 ou 409 /errors/post-not-in-comuna. Comuna arquivada: 409 /errors/comuna-archived. Não gera notificação. A resposta é a lista atual, na ordem.',
            ),
        ),
        new Delete(
            uriTemplate: '/comunas/{slug}/pinned-posts/{postId}',
            name: 'comuna_unpin_post',
            read: false,
            status: 204,
            security: "is_granted('ROLE_USER')",
            processor: PinnedPostProcessor::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Desafixa uma publicação. Idempotente.',
                description: 'Só proprietário ou moderação. Desafixar de novo não muda nada. Comuna arquivada: 409 /errors/comuna-archived. Não gera notificação.',
            ),
        ),
        new Put(
            uriTemplate: '/comunas/{slug}/pinned-posts/{postId}/position',
            name: 'comuna_move_pinned_post',
            read: false,
            input: PinPositionInput::class,
            status: 200,
            security: "is_granted('ROLE_USER')",
            processor: PinnedPostProcessor::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Muda a ordem de uma publicação já fixada.',
                description: 'Corpo: {"position": 1|2|3}. 1 é a primeira. Só proprietário ou moderação. Publicação que não está fixada: 409 /errors/post-not-pinned. A mesma posição não muda nada. A resposta é a lista atual.',
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
