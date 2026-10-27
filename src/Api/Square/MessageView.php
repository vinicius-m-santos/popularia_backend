<?php

declare(strict_types=1);

namespace App\Api\Square;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Post\ContentRemovalInput;
use App\Api\Profile\AuthorView;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/squares/{id}/messages',
            name: 'square_send',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['id' => new Link(fromClass: SquareView::class, identifiers: ['id'])],
            read: false,
            processor: SquareProcessor::class,
            input: MessageInput::class,
            status: 201,
            openapi: new Operation(tags: ['Praças'], summary: 'Envia texto OU figurinha; clientId deduplica retries.'),
        ),
        new Delete(
            uriTemplate: '/squares/{id}/messages/{messageId}',
            name: 'square_delete',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['id' => new Link(fromClass: SquareView::class, identifiers: ['id']), 'messageId' => new Link(fromClass: MessageView::class, identifiers: ['id'])],
            read: false,
            processor: SquareProcessor::class,
            deserialize: false,
            validate: false,
            status: 204,
            output: false,
            openapi: new Operation(tags: ['Praças'], summary: 'Exclui a própria mensagem.'),
        ),
        new Post(
            uriTemplate: '/squares/{id}/messages/{messageId}/removal',
            name: 'square_remove',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['id' => new Link(fromClass: SquareView::class, identifiers: ['id']), 'messageId' => new Link(fromClass: MessageView::class, identifiers: ['id'])],
            read: false,
            processor: SquareProcessor::class,
            input: ContentRemovalInput::class,
            status: 204,
            output: false,
            openapi: new Operation(tags: ['Praças'], summary: 'Remove pela moderação, com motivo auditado.'),
        ),

        new Post(
            uriTemplate: '/squares/{id}/messages/{messageId}/reports',
            name: 'square_report',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['id' => new Link(fromClass: SquareView::class, identifiers: ['id']), 'messageId' => new Link(fromClass: MessageView::class, identifiers: ['id'])],
            read: false,
            processor: SquareProcessor::class,
            input: ContentRemovalInput::class,
            status: 204,
            output: false,
            openapi: new Operation(tags: ['Praças'], summary: 'Registra denúncia persistente, sem copiar conteúdo privado.'),
        ),
    ],
)]
final readonly class MessageView
{
    /** @param array<string, string>|null $sticker
     * @param array{id: string, author: ?AuthorView, body: ?string, status: string}|null $replyTo
     * @param list<array{emoji: string, count: int, mine: bool}> $reactions
     * @param array<string, bool> $viewer
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $squareId,
        public string $status,
        public ?string $body,
        public ?AuthorView $author,
        public ?array $sticker,
        public ?array $replyTo,
        public array $reactions,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $editedAt,
        public int $revision,
        public ?string $clientId,
        public array $viewer,
    ) {
    }
}
