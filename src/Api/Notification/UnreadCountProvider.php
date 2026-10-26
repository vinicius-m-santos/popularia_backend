<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\NotificationRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<UnreadCount> */
final readonly class UnreadCountProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private NotificationRepository $notifications,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UnreadCount
    {
        return new UnreadCount($this->notifications->countUnread($this->currentUser->get()));
    }
}
