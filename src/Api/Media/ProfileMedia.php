<?php

declare(strict_types=1);

namespace App\Api\Media;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;

/** Leitura pública da foto e da capa atuais. A autorização de troca fica nos POST. */
#[ApiResource(operations: [
    new Get(
        uriTemplate: '/media/avatars/{userId}/{fileId}',
        controller: AvatarFileController::class,
        read: false,
        output: false,
        openapi: new Operation(tags: ['Perfis'], summary: 'Bytes da foto de perfil atual. A URL muda quando a foto muda e pode ser cacheada.'),
    ),
    new Get(
        uriTemplate: '/media/comuna-covers/{comunaId}/{fileId}',
        controller: CoverFileController::class,
        read: false,
        output: false,
        openapi: new Operation(tags: ['Comunas'], summary: 'Bytes da capa atual da comuna. A URL muda quando a capa muda e pode ser cacheada.'),
    ),
    new Get(
        uriTemplate: '/media/comuna-marks/{comunaId}/{fileId}',
        controller: MarkFileController::class,
        read: false,
        output: false,
        openapi: new Operation(tags: ['Comunas'], summary: 'Bytes da marca (imagem de perfil) atual da comuna. A URL muda quando a marca muda e pode ser cacheada.'),
    ),
])]
final readonly class ProfileMedia
{
    public function __construct(#[ApiProperty(identifier: true)] public string $id = '')
    {
    }
}
