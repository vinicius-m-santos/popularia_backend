<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Profile\AuthorView;
use App\Entity\Notification;
use App\Enum\NotificationType;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/me/notifications/{id}',
            requirements: ['id' => '[0-9a-fA-F-]{36}'],
            security: "is_granted('ROLE_USER')",
            provider: NotificationProvider::class,
            openapi: new Operation(tags: ['Notificações'], summary: 'Revalida uma notificação e seu destino.'),
        ),
        new Put(
            uriTemplate: '/me/notifications/{id}/read',
            status: 200,
            read: false,
            deserialize: false,
            validate: false,
            security: "is_granted('ROLE_USER')",
            processor: NotificationReadProcessor::class,
            openapi: new Operation(tags: ['Notificações'], summary: 'Marca uma notificação própria como lida (idempotente).'),
        ),
    ],
)]
final readonly class NotificationView
{
    /** @param array{kind: string, id: string} $target */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $type,
        public ?AuthorView $actor,
        public ?string $subject,
        public ?string $quote,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $readAt,
        public array $target,
    ) {
    }

    public static function fromNotification(Notification $notification): self
    {
        $actor = $notification->getActor();
        $comuna = $notification->getComuna();
        $post = $notification->getPost();
        $target = match ($notification->getType()) {
            NotificationType::Follow => ['kind' => 'profile', 'id' => $actor?->getHandle() ?? ''],
            NotificationType::Comment, NotificationType::Reply => ['kind' => 'post', 'id' => $post?->getId()->toRfc4122() ?? ''],
            NotificationType::JoinRequested => ['kind' => 'join_requests', 'id' => $comuna?->getSlug() ?? ''],
            default => ['kind' => 'comuna', 'id' => $comuna?->getSlug() ?? ''],
        };

        return new self(
            $notification->getId()->toRfc4122(),
            $notification->getType()->value,
            null === $actor ? null : AuthorView::fromUser($actor),
            $comuna?->getName(),
            null === $notification->getComment() ? null : mb_substr($notification->getComment()->getBody(), 0, 160),
            $notification->getCreatedAt(),
            $notification->getReadAt(),
            $target,
        );
    }
}
