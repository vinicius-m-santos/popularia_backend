<?php

declare(strict_types=1);

namespace App\Api\Square;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/squares/{id}/messages',
            name: 'square_messages',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['id' => new Link(fromClass: SquareView::class, identifiers: ['id'])],
            provider: SquareProvider::class,
            openapi: new Operation(tags: ['Praças'], summary: 'Recentes/cursor para anteriores; after para alterações incrementais (inclusive remoções e reações).', parameters: [new Parameter('cursor', 'query', 'nextCursor da página anterior.'), new Parameter('limit', 'query', '1..50; padrão 20.', schema: ['type' => 'integer']), new Parameter('after', 'query', 'changeCursor anterior; não usar com cursor.', schema: ['type' => 'integer']), new Parameter('refreshIds', 'query', 'Até 200 UUIDs separados por vírgula; revalida conteúdo carregado, replies e bloqueios.')]),
        ),
    ],
)]
final readonly class MessagePage
{
    /** @param list<MessageView> $items
     * @param list<MessageView> $refreshed
     */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $items,
        public ?string $nextCursor,
        public int $changeCursor,
        public bool $hasMoreChanges,
        #[ApiProperty(readableLink: true)]
        public array $refreshed,
        #[ApiProperty(readableLink: true)]
        public SquareView $square,
    ) {
    }
}
