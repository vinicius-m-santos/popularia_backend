<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\NotificationRepository;
use App\Security\CurrentUser;
use Symfony\Component\Clock\ClockInterface;

/** @implements ProcessorInterface<mixed, UnreadCount> */
final readonly class ReadAllProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private NotificationRepository $notifications,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UnreadCount
    {
        $viewer = $this->currentUser->get();
        $this->notifications->markAllRead($viewer, $this->clock->now());

        return new UnreadCount($this->notifications->countUnread($viewer));
    }
}
