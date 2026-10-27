<?php

declare(strict_types=1);

namespace App\Api\Square;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(
    operations: [
        new Put(
            uriTemplate: '/squares/{id}/messages/{messageId}/reactions/{emoji}',
            name: 'square_react',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['emoji' => new Link(fromClass: self::class, identifiers: ['emoji']), 'id' => new Link(fromClass: SquareView::class, identifiers: ['id']), 'messageId' => new Link(fromClass: MessageView::class, identifiers: ['id'])],
            read: false,
            processor: SquareProcessor::class,
            deserialize: false,
            validate: false,
            status: 200,
            output: MessageView::class,
            openapi: new Operation(tags: ['Praças'], summary: 'Adiciona reação idempotente; conjunto limitado.'),
        ),
        new Delete(
            uriTemplate: '/squares/{id}/messages/{messageId}/reactions/{emoji}',
            name: 'square_unreact',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['emoji' => new Link(fromClass: self::class, identifiers: ['emoji']), 'id' => new Link(fromClass: SquareView::class, identifiers: ['id']), 'messageId' => new Link(fromClass: MessageView::class, identifiers: ['id'])],
            read: false,
            processor: SquareProcessor::class,
            deserialize: false,
            validate: false,
            status: 200,
            output: MessageView::class,
            openapi: new Operation(tags: ['Praças'], summary: 'Remove a reação própria; idempotente.'),
        ),
    ],
)]
final readonly class ReactionManagement
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $emoji,
    ) {
    }
}
