<?php

declare(strict_types=1);

namespace App\Api\Comment;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Api\Post\PostView;

/** Página de comentários de uma publicação, do mais antigo para o mais novo. */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/posts/{id}/comments',
            uriVariables: ['id' => new Link(fromClass: PostView::class, identifiers: ['id'])],
            provider: CommentPageProvider::class,
            openapi: new Operation(
                tags: ['Comentários'],
                summary: 'Comentários de uma publicação (lista plana com parentId).',
                parameters: [
                    new Parameter('cursor', 'query', 'Valor de nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'Itens por página (1 a 50, padrão 20).', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
)]
final readonly class CommentPage
{
    /** @param list<CommentView> $items */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
