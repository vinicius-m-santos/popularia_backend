<?php

declare(strict_types=1);

namespace App\Api\PostMedia;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\RequestBody;

#[ApiResource(shortName: 'PostMedia', operations: [
    new Post(
        uriTemplate: '/post-media',
        security: "is_granted('ROLE_USER')",
        inputFormats: ['multipart' => ['multipart/form-data']],
        controller: UploadController::class,
        deserialize: false,
        validate: false,
        read: false,
        input: false,
        status: 201,
        openapi: new Operation(tags: ['Publicações'], summary: 'Prepara uma imagem (10 MiB) ou vídeo curto (30 s/100 MiB) para uma publicação.', requestBody: new RequestBody(content: new \ArrayObject([
            'multipart/form-data' => ['schema' => ['type' => 'object', 'required' => ['file', 'metadata'], 'properties' => [
                'file' => ['type' => 'string', 'format' => 'binary'],
                'metadata' => ['type' => 'string', 'description' => 'JSON: clientId UUID e kind image|video.'],
            ]]],
        ]))),
    ),
])]
final readonly class PostMediaView
{
    public function __construct(
        #[ApiProperty(identifier: true)] public string $id,
        public string $type,
        public string $mimeType,
        public int $byteSize,
        public int $width,
        public int $height,
        public ?int $durationMs,
        public ?string $url = null,
    ) {
    }
}
