<?php

declare(strict_types=1);

namespace App\Content;

use App\Entity\Comuna;
use App\Entity\ComunaEvent;
use App\Entity\EventReminder;
use App\Entity\User;
use App\Enum\AttendanceStatus;
use App\Repository\EventReminderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** Mantém no máximo um lembrete pendente por pessoa e evento, recalculado quando a data muda. */
final readonly class EventReminders
{
    public function __construct(
        private EntityManagerInterface $em,
        private EventReminderRepository $reminders,
        private ClockInterface $clock,
    ) {
    }

    public function sync(ComunaEvent $event, User $user, AttendanceStatus $status): void
    {
        $existing = $this->reminders->findOne($event, $user);
        $when = $status->wantsReminder() && !$event->isCancelled()
            ? EventSchedule::remindAt($this->clock->now(), $event->getStartsAt())
            : null;
        if (null === $when) {
            if (null !== $existing && null === $existing->getSentAt()) {
                $this->em->remove($existing);
            }

            return;
        }
        if (null === $existing) {
            $this->em->persist(new EventReminder($event, $user, $when));

            return;
        }
        if ($existing->wasSentFor($event->getStartsAt())) {
            return;
        }
        if (null === $existing->getSentAt() && $existing->getRemindAt()->getTimestamp() === $when->getTimestamp()) {
            return;
        }
        $existing->reschedule($when);
    }

    /** Reentrada recupera só lembretes ausentes. O histórico enviado não é reiniciado. */
    public function restoreMembership(Comuna $comuna, User $user): void
    {
        $this->em->getConnection()->executeStatement(<<<'SQL'
            INSERT INTO event_reminder (id, event_id, user_id, remind_at, sent_at, sent_for_starts_at)
            SELECT gen_random_uuid(), e.id, a.user_id,
                e.starts_at - CASE WHEN e.starts_at < CAST(:now AS timestamptz) + INTERVAL '24 hours'
                    THEN INTERVAL '1 hour' ELSE INTERVAL '24 hours' END, NULL, NULL
            FROM event_attendance a INNER JOIN comuna_event e ON e.id = a.event_id
            WHERE e.comuna_id = :comuna AND a.user_id = :user
                AND a.status IN ('going', 'maybe') AND e.status = 'scheduled'
                AND e.starts_at >= CAST(:now AS timestamptz) + INTERVAL '2 hours'
            ON CONFLICT (event_id, user_id) DO NOTHING
            SQL, [
            'comuna' => $comuna->getId()->toRfc4122(),
            'user' => $user->getId()->toRfc4122(),
            'now' => $this->clock->now()->format(\DATE_ATOM),
        ]);
    }

    public function rescheduleAll(ComunaEvent $event): void
    {
        $when = $event->isCancelled() ? null : EventSchedule::remindAt($this->clock->now(), $event->getStartsAt());
        foreach ($this->reminders->forEvent($event) as $reminder) {
            if (null === $when) {
                if (null === $reminder->getSentAt()) {
                    $this->em->remove($reminder);
                }
                continue;
            }
            if ($reminder->wasSentFor($event->getStartsAt())) {
                continue;
            }
            $reminder->reschedule($when);
        }
    }

    public function dropPending(ComunaEvent $event): void
    {
        foreach ($this->reminders->forEvent($event) as $reminder) {
            if (null === $reminder->getSentAt()) {
                $this->em->remove($reminder);
            }
        }
    }
}
