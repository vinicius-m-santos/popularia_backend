<?php

declare(strict_types=1);

namespace App\Api\Library;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(shortName: 'LibraryCollectionPage', security: "is_granted('ROLE_USER')", cacheHeaders: ['max_age' => 0, 'shared_max_age' => 0, 'public' => false], operations: [new Get(uriTemplate: '/library/collections', provider: LibraryProvider::class, name: 'library_collections', openapi: new Operation(tags: ['Biblioteca'], summary: 'Coleções paginadas por scope public/personal/comuna; owner UUID, comuna slug, cursor, limit.'))])]
final readonly class CollectionPage
{
    /** @param list<CollectionView> $items */
    public function __construct(#[ApiProperty(readableLink: true)] public array $items, public ?string $nextCursor)
    {
    }
}
