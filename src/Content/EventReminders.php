<?php

declare(strict_types=1);

namespace App\Content;

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
