<?php

declare(strict_types=1);

namespace App\Api\Square;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Comuna\ComunaView;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/squares/{id}',
            name: 'square_detail',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['id' => new Link(fromClass: SquareView::class, identifiers: ['id'])],
            provider: SquareProvider::class,
            openapi: new Operation(tags: ['Praças'], summary: 'Obtém metadados; privada sem acesso retorna somente o estado bloqueado.'),
        ),
        new Get(
            uriTemplate: '/comunas/{slug}/square',
            name: 'square_comuna',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            provider: SquareProvider::class,
            openapi: new Operation(tags: ['Praças'], summary: 'Praça estrutural criada automaticamente com a Comuna, conforme acesso.'),
        ),
    ],
)]
final readonly class SquareView
{
    /** @param array{id: string, slug: string, name: string, tone: string, visibility: string}|null $comuna
     * @param array{canRead: bool, isMember: bool, canWrite: bool, canManage: bool, joinRequest: ?string} $viewer
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public ?array $comuna,
        public string $title,
        public ?string $status,
        public ?\DateTimeImmutable $closedAt,
        public ?int $version,
        public ?int $presence,
        public ?int $unread,
        public ?string $lastReadId,
        #[ApiProperty(readableLink: true)]
        public ?MessageView $lastMessage,
        public array $viewer,
    ) {
    }
}
