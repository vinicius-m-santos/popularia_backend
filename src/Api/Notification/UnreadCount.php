<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/me/notifications/unread-count',
            security: "is_granted('ROLE_USER')",
            provider: UnreadCountProvider::class,
            openapi: new Operation(tags: ['Notificações'], summary: 'Total de notificações visíveis não lidas.'),
        ),
        new Put(
            uriTemplate: '/me/notifications/read-all',
            status: 200,
            read: false,
            deserialize: false,
            validate: false,
            security: "is_granted('ROLE_USER')",
            processor: ReadAllProcessor::class,
            openapi: new Operation(tags: ['Notificações'], summary: 'Marca todas as notificações próprias como lidas.'),
        ),
    ],
)]
final readonly class UnreadCount
{
    public function __construct(public int $unreadCount)
    {
    }
}
