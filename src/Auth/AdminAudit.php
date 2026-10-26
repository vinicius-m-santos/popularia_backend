<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Registra ações administrativas e consultas a dados sensíveis (tabela só de inserção). */
final class AdminAudit
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @param array<string, scalar|null> $details */
    public function record(?User $actor, string $action, ?Uuid $targetUserId, string $reason, array $details = []): void
    {
        $this->em->persist(new AdminAuditLog(
            $actor,
            null === $actor ? 'console' : 'api',
            $action,
            $targetUserId,
            $reason,
            $details,
            $this->clock->now(),
        ));
        $this->em->flush();
    }
}
