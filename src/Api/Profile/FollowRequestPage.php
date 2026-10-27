<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(shortName: 'FollowRequests', security: "is_granted('ROLE_USER')", cacheHeaders: ['max_age' => 0, 'shared_max_age' => 0, 'public' => false], operations: [
    new Get(uriTemplate: '/me/follow-requests', provider: FollowRequestHandler::class, openapi: new Operation(tags: ['Perfis'], summary: 'Pedidos de seguidores pendentes, paginados.')),
    new Put(uriTemplate: '/me/follow-requests/{id}', name: 'follow_approve', read: false, deserialize: false, validate: false, output: false, status: 204, processor: FollowRequestHandler::class, openapi: new Operation(tags: ['Perfis'], summary: 'Aprova seguidor sem alterar acessos de documentos.')),
    new Delete(uriTemplate: '/me/follow-requests/{id}', name: 'follow_reject', read: false, output: false, processor: FollowRequestHandler::class, openapi: new Operation(tags: ['Perfis'], summary: 'Recusa pedido pendente.')),
])]
final readonly class FollowRequestPage
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(public array $items, public ?string $nextCursor, #[ApiProperty(identifier: true)] public ?string $id = null)
    {
    }
}
