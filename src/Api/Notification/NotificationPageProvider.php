<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Pagination;
use App\Entity\Notification;
use App\Repository\NotificationRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<NotificationPage> */
final readonly class NotificationPageProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private NotificationRepository $notifications,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NotificationPage
    {
        $page = Pagination::fromContext($context);
        [$rows, $next] = $page->slice($this->notifications->findVisiblePage($this->currentUser->get(), $page->cursor, $page->limit), static fn (Notification $n) => $n->getId());

        return new NotificationPage(array_map(NotificationView::fromNotification(...), $rows), $next);
    }
}
