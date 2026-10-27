<?php

declare(strict_types=1);

namespace App\Api\Library;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(shortName: 'LibraryCollection', security: "is_granted('ROLE_USER')", cacheHeaders: ['max_age' => 0, 'shared_max_age' => 0, 'public' => false], operations: [
    new Get(uriTemplate: '/library/collections/{id}', provider: LibraryProvider::class, name: 'library_collection', openapi: new Operation(tags: ['Biblioteca'], summary: 'Coleção; somente documentos atualmente acessíveis contam.')),
    new Post(uriTemplate: '/library/collections', read: false, input: CollectionInput::class, name: 'library_create_collection', processor: LibraryProcessor::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Cria coleção pessoal ou na comuna explícita.')),
    new Patch(uriTemplate: '/library/collections/{id}', read: false, input: CollectionInput::class, name: 'library_edit_collection', processor: LibraryProcessor::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Edita nome e descrição; não muda origem.')),
    new Delete(uriTemplate: '/library/collections/{id}', read: false, output: false, name: 'library_delete_collection', processor: LibraryProcessor::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Exclui apenas coleção vazia.')),
])]
final readonly class CollectionView
{
    /** @param array<string, mixed> $origin */
    public function __construct(#[ApiProperty(identifier: true)] public string $id, public string $name, public string $description, public array $origin, public int $documentCount, public bool $canManage, public bool $canAdd)
    {
    }
}
