<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\EventReminder;
use App\Repository\ComunaMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Roda dentro de app:notifications:deliver, no mesmo ciclo do push.
 * A fila é a tabela event_reminder: sobrevive a restart e deploy.
 */
final readonly class EventReminderWorker
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private NotificationCreator $notifications,
        private ComunaMembershipRepository $memberships,
    ) {
    }

    public function dispatchDue(int $limit): int
    {
        $sent = 0;
        for ($i = 0; $i < max(1, min(100, $limit)); ++$i) {
            $delivered = $this->em->getConnection()->transactional(function (): bool {
                $now = $this->clock->now()->format(\DATE_ATOM);
                $id = $this->em->getConnection()->fetchOne(
                    "SELECT r.id FROM event_reminder r INNER JOIN comuna_event e ON e.id = r.event_id WHERE r.sent_at IS NULL AND r.remind_at <= :now AND e.status = 'scheduled' AND COALESCE(e.ends_at, e.starts_at) > :now ORDER BY r.remind_at, r.id FOR UPDATE OF r SKIP LOCKED LIMIT 1",
                    ['now' => $now],
                );
                if (!\is_string($id) || '' === $id) {
                    return false;
                }
                $reminder = $this->em->find(EventReminder::class, Uuid::fromString($id));
                if (!$reminder instanceof EventReminder || null !== $reminder->getSentAt()) {
                    return false;
                }
                $event = $reminder->getEvent();
                $user = $reminder->getUser();
                $attendance = $this->em->getRepository(\App\Entity\EventAttendance::class)->findOneBy(['event' => $event, 'user' => $user]);
                $interested = null !== $attendance && $attendance->getStatus()->wantsReminder();
                $allowed = $user->isActive() && !$event->isCancelled() && !$event->getComuna()->isSuspended() && ($event->getComuna()->isPublic() || null !== $this->memberships->findMembership($event->getComuna(), $user));
                if (!$interested || !$allowed) {
                    $this->em->remove($reminder);
                    $this->em->flush();

                    return true;
                }
                $this->notifications->eventReminder($event, $user);
                $reminder->markSent($this->clock->now(), $event->getStartsAt());
                $this->em->flush();

                return true;
            });
            if (!$delivered) {
                break;
            }
            ++$sent;
        }

        return $sent;
    }
}
