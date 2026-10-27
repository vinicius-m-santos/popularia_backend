<?php

declare(strict_types=1);

namespace App\Api\Square;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/squares/{id}/sticker-packs',
            name: 'square_stickers',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['id' => new Link(fromClass: SquareView::class, identifiers: ['id'])],
            provider: SquareProvider::class,
            openapi: new Operation(tags: ['Praças'], summary: 'Pacote Popularia e da comuna; exige acesso.'),
        ),
        new Post(
            uriTemplate: '/squares/{id}/stickers',
            name: 'square_add_sticker',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['id' => new Link(fromClass: SquareView::class, identifiers: ['id'])],
            read: false,
            processor: SquareProcessor::class,
            input: StickerInput::class,
            status: 201,
            output: StickerPage::class,
            openapi: new Operation(tags: ['Praças'], summary: 'Adiciona figurinha simbólica da comuna (moderação, até 40).'),
        ),

    ],
)]
final readonly class StickerPage
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(public array $items)
    {
    }
}
