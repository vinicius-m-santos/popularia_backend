<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Repository\NotificationRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** @implements ProcessorInterface<mixed, NotificationView> */
final readonly class NotificationReadProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private NotificationRepository $notifications,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NotificationView
    {
        $notification = $this->notifications->findVisible($this->currentUser->get(), (string) ($uriVariables['id'] ?? ''))
            ?? throw ApiProblem::notFound('Esta notificação não está mais disponível.');
        $notification->markRead($this->clock->now());
        $this->em->flush();

        return NotificationView::fromNotification($notification);
    }
}
