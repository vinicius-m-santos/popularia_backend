<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Repository\NotificationRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<NotificationView> */
final readonly class NotificationProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private NotificationRepository $notifications,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NotificationView
    {
        $notification = $this->notifications->findVisible($this->currentUser->get(), (string) ($uriVariables['id'] ?? ''))
            ?? throw ApiProblem::notFound('Esta notificação não está mais disponível.');

        return NotificationView::fromNotification($notification);
    }
}
