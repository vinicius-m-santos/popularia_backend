<?php

declare(strict_types=1);

namespace App\Api\Square;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(
    operations: [
        new Delete(
            uriTemplate: '/squares/{id}/stickers/{stickerId}',
            name: 'square_delete_sticker',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['stickerId' => new Link(fromClass: self::class, identifiers: ['id']), 'id' => new Link(fromClass: SquareView::class, identifiers: ['id'])],
            read: false,
            processor: SquareProcessor::class,
            deserialize: false,
            validate: false,
            status: 204,
            output: false,
            openapi: new Operation(tags: ['Praças'], summary: 'Desativa figurinha da comuna; mantém mensagens históricas.'),
        ),
    ],
)]
final readonly class StickerManagement
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
    ) {
    }
}
