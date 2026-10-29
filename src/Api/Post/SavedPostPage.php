<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;

#[ApiResource(
    shortName: 'SavedPostPage',
    security: "is_granted('ROLE_USER')",
    cacheHeaders: ['max_age' => 0, 'shared_max_age' => 0, 'public' => false],
    operations: [
        new Get(
            uriTemplate: '/me/saved-posts',
            provider: SavedPostProvider::class,
            name: 'saved_posts',
            openapi: new Operation(
                tags: ['Biblioteca'],
                summary: 'Publicações que a pessoa salvou, da mais recente para a mais antiga.',
                description: 'Lista privada. Não revela quem salvou o quê. Publicação apagada, removida, de perfil ou comuna sem acesso, com bloqueio ou de conta suspensa simplesmente não aparece: salvar não guarda cópia nem autorização.',
                parameters: [
                    new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'Itens por página (1 a 50, padrão 20).', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
)]
final readonly class SavedPostPage
{
    /** @param list<SavedPostItem> $items */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}

final readonly class SavedPostItem
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $savedAt,
        #[ApiProperty(readableLink: true)]
        public PostView $post,
    ) {
    }
}
