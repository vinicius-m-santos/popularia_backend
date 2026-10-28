<?php

declare(strict_types=1);

namespace App\Api\Library;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(
    shortName: 'LibraryDocument',
    security: "is_granted('ROLE_USER')",
    cacheHeaders: ['max_age' => 0, 'shared_max_age' => 0, 'public' => false],
    operations: [
        new Get(uriTemplate: '/library/documents/{id}', provider: LibraryProvider::class, name: 'library_document', openapi: new Operation(tags: ['Biblioteca'], summary: 'Documento autorizado ou cartão mínimo bloqueado.')),
        new Post(uriTemplate: '/library/documents', inputFormats: ['multipart' => ['multipart/form-data']], controller: UploadController::class, deserialize: false, validate: false, read: false, input: false, status: 201, openapi: new Operation(tags: ['Biblioteca'], summary: 'Envia PDF (25 MiB) e metadados; somente cria documento após validar e armazenar.', requestBody: new \ApiPlatform\OpenApi\Model\RequestBody(content: new \ArrayObject(['multipart/form-data' => ['schema' => ['type' => 'object', 'required' => ['file', 'metadata'], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary'], 'metadata' => ['type' => 'string', 'description' => 'JSON: title, author (opcional), description, collectionId, access, topic, isPublic, sharedWith (slugs), clientId (UUID de idempotência).']]]]])))),
        new Patch(uriTemplate: '/library/documents/{id}', name: 'library_edit', input: DocumentInput::class, read: false, processor: LibraryProcessor::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Edita metadados e acesso, sem substituir arquivo. Use clearAuthor para remover a autoria.')),
        new Delete(uriTemplate: '/library/documents/{id}', name: 'library_delete', read: false, output: false, processor: LibraryProcessor::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Exclusão lógica do original.')),
        new Get(uriTemplate: '/library/documents/{id}/file', name: 'library_file', controller: DownloadController::class, read: false, output: false, openapi: new Operation(tags: ['Biblioteca'], summary: 'Entrega bytes PDF com autorização atual, sem URL pública e sem cache.')),
        new Put(uriTemplate: '/library/documents/{id}/saved', name: 'library_save', read: false, deserialize: false, validate: false, output: false, status: 204, processor: LibraryProcessor::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Salva referência; idempotente.')),
        new Delete(uriTemplate: '/library/documents/{id}/saved', name: 'library_unsave', read: false, output: false, processor: LibraryProcessor::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Retira dos salvos, mesmo sem acesso ao documento.')),
        new Put(uriTemplate: '/library/documents/{id}/comunas/{slug}', uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id']), 'slug' => new Link(fromClass: \App\Api\Comuna\ComunaView::class, identifiers: ['slug'])], name: 'library_share', read: false, deserialize: false, validate: false, status: 204, output: false, processor: LibraryProcessor::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Distribui documento pessoal à comuna; exige participação atual.')),
        new Delete(uriTemplate: '/library/documents/{id}/comunas/{slug}', uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id']), 'slug' => new Link(fromClass: \App\Api\Comuna\ComunaView::class, identifiers: ['slug'])], name: 'library_unshare', read: false, output: false, processor: LibraryProcessor::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Retira distribuição, preserva original.')),
        new Post(uriTemplate: '/library/documents/{id}/reports', name: 'library_report', read: false, input: ReasonInput::class, status: 204, output: false, processor: LibraryProcessor::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Denuncia documento acessível.')),
        new Post(uriTemplate: '/library/documents/{id}/removal', name: 'library_remove', read: false, input: ReasonInput::class, status: 204, output: false, processor: LibraryProcessor::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Moderação remove original da comuna ou distribuição pessoal; plataforma remove conteúdo denunciado.')),
    ],
)]
final readonly class DocumentView
{
    /** @param array<string, mixed>|null $origin
     * @param array<string, mixed>|null       $collection
     * @param array<string, mixed>|null       $responsible
     * @param array<string, bool>             $viewer
     * @param list<array<string, mixed>>|null $distributions
     */
    public function __construct(
        #[ApiProperty(identifier: true)] public string $id,
        public string $state,
        public ?string $title = null,
        public ?string $author = null,
        public ?string $description = null,
        public ?array $origin = null,
        public ?array $collection = null,
        public ?array $responsible = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?int $byteSize = null,
        public ?int $pageCount = null,
        public ?string $topic = null,
        public ?string $access = null,
        public ?bool $isPublic = null,
        public bool $saved = false,
        public array $viewer = [],
        public ?array $distributions = null,
        public ?string $message = null,
    ) {
    }
}
