<?php

declare(strict_types=1);

namespace App\Api\Library;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(shortName: 'LibraryDocumentPage', security: "is_granted('ROLE_USER')", cacheHeaders: ['max_age' => 0, 'shared_max_age' => 0, 'public' => false], operations: [
    new Get(uriTemplate: '/library/documents', provider: LibraryProvider::class, name: 'library_documents', openapi: new Operation(tags: ['Biblioteca'], summary: 'Biblioteca paginada. scope public/personal/comuna; owner UUID, comuna slug, q, topic, origin all/people/comunas, collection UUID, cursor, limit 1..50.')),
    new Get(uriTemplate: '/me/saved-documents', provider: LibraryProvider::class, name: 'library_saved', openapi: new Operation(tags: ['Biblioteca'], summary: 'Referências salvas; indisponíveis não revelam metadados.')),
])]
final readonly class DocumentPage
{
    /** @param list<DocumentView> $items */
    public function __construct(#[ApiProperty(readableLink: true)] public array $items, public ?string $nextCursor)
    {
    }
}
