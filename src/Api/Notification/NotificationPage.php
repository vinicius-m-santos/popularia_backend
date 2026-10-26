<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/me/notifications',
            security: "is_granted('ROLE_USER')",
            provider: NotificationPageProvider::class,
            openapi: new Operation(
                tags: ['Notificações'],
                summary: 'Minhas notificações, mais recentes primeiro.',
                parameters: [
                    new Parameter('cursor', 'query', 'nextCursor da página anterior.'),
                    new Parameter('limit', 'query', 'Itens por página (1 a 50, padrão 20).', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
)]
final readonly class NotificationPage
{
    /** @param list<NotificationView> $items */
    public function __construct(
        #[ApiProperty(readableLink: true)]
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
