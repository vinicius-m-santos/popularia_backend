<?php

declare(strict_types=1);

namespace App\Api\Discovery;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Api\Comuna\ComunaView;
use App\Api\Post\PostView;
use App\Api\Topic\TopicView;

#[ApiResource(
    shortName: 'Discovery',
    operations: [
        new Get(
            uriTemplate: '/explore',
            security: "is_granted('ROLE_USER')",
            cacheHeaders: ['max_age' => 0, 'shared_max_age' => 0, 'public' => false],
            provider: DiscoveryProvider::class,
            openapi: new Operation(tags: ['Descoberta'], summary: 'Previews de descoberta; topic opcional restringe ao assunto oficial.', parameters: [new Parameter('topic', 'query', 'Código oficial do assunto.')]),
        ),
        new Get(
            uriTemplate: '/search',
            security: "is_granted('ROLE_USER')",
            cacheHeaders: ['max_age' => 0, 'shared_max_age' => 0, 'public' => false],
            provider: DiscoveryProvider::class,
            openapi: new Operation(tags: ['Descoberta'], summary: 'Busca textual com relevância, sem acentos.', parameters: [
                new Parameter('q', 'query', 'Termo de 3 a 100 caracteres (mínimo de três letras/números seguidos). Vazio lista a categoria. @ inicial busca handle.'),
                new Parameter('type', 'query', 'Categoria; all retorna até três itens por seção, sem cursor.', schema: ['type' => 'string', 'enum' => ['all', 'comunas', 'people', 'posts', 'events', 'documents']]),
                new Parameter('topic', 'query', 'Assunto principal. Não admite a categoria people.'),
                new Parameter('limit', 'query', 'Itens por categoria (1 a 50).', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]),
                new Parameter('cursor', 'query', 'Cursor de next da categoria. Busca com termo usa v1_relevância_desempate_id. Sem termo, UUID. Não aceito com all.'),
            ]),
        ),
    ],
)]
final readonly class DiscoveryView
{
    /**
     * @param list<TopicView>                                                                                $topics
     * @param list<ComunaView>                                                                               $comunas
     * @param list<DiscoveryPerson>                                                                          $people
     * @param list<PostView>                                                                                 $posts
     * @param list<PostView>                                                                                 $recentPosts
     * @param array{comunas: ?string, people: ?string, posts: ?string, events: ?string, documents?: ?string} $next
     * @param list<\App\Api\Event\EventView>                                                                 $events
     */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $topics,
        #[ApiProperty(readableLink: true)]
        public array $comunas,
        public array $people,
        #[ApiProperty(readableLink: true)]
        public array $posts,
        #[ApiProperty(readableLink: true)]
        public array $recentPosts,
        public array $next,
        /** @var list<\App\Api\Library\DocumentView> */
        #[ApiProperty(readableLink: true)]
        public array $documents = [],
        #[ApiProperty(readableLink: true)]
        public array $events = [],
    ) {
    }
}
