<?php

declare(strict_types=1);

namespace App\Content;

use App\Api\ApiProblem;
use App\Entity\Comuna;
use App\Entity\ComunaEvent;
use App\Entity\EventAttendance;
use App\Entity\User;
use App\Enum\AttendanceStatus;
use App\Enum\EventFormat;
use App\Enum\NotificationType;
use App\Gamification\GamificationAction;
use App\Gamification\GamificationService;
use App\Notification\NotificationCreator;
use App\Repository\EventAttendanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Criar, editar, cancelar e responder. O banco é a autoridade da presença:
 * a linha do evento é travada e a chave (evento, pessoa) é única.
 */
final readonly class ComunaEvents
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private ComunaModerationPolicy $moderation,
        private ContentPolicy $content,
        private RemovedContentFilter $filter,
        private EventAttendanceRepository $attendance,
        private EventReminders $reminders,
        private NotificationCreator $notifications,
        private GamificationService $gamification,
    ) {
    }

    public function create(Comuna $comuna, User $actor, EventFormat $format, string $title, string $description, ?string $placeName, ?string $address, ?string $onlineUrl, string $timeZone, string $startsAt, ?string $endsAt): ComunaEvent
    {
        $this->assertOrganizer($comuna, $actor);
        [$place, $street, $url] = $this->fields($comuna, $format, $title, $description, $placeName, $address, $onlineUrl);
        [$start, $end] = EventSchedule::bounds($startsAt, $endsAt, $timeZone, $this->clock->now(), false);
        $event = new ComunaEvent($comuna, $actor, $title, $description, $format, $place, $street, $url, $timeZone, $start, $end, $this->clock->now());
        $this->em->persist($event);

        return $event;
    }

    public function update(ComunaEvent $event, User $actor, EventFormat $format, string $title, string $description, ?string $placeName, ?string $address, ?string $onlineUrl, string $timeZone, string $startsAt, ?string $endsAt): ComunaEvent
    {
        $this->em->lock($event, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        $this->assertOrganizer($event->getComuna(), $actor);
        if ($event->isCancelled()) {
            throw ApiProblem::conflict('/errors/event-cancelled', 'Este evento foi cancelado.');
        }
        if ($timeZone !== $event->getTimeZone()) {
            throw ApiProblem::badRequest('/errors/event-timezone', 'O fuso anunciado do evento não muda.', 'Altere a data e a hora dentro do mesmo fuso.');
        }
        $before = [$event->getStartsAt()->getTimestamp(), $event->getEndsAt()?->getTimestamp(), $event->getPlaceName(), $event->getAddress(), $event->getOnlineUrl()];
        [$place, $street, $url] = $this->fields($event->getComuna(), $format, $title, $description, $placeName, $address, $onlineUrl);
        [$start, $end] = EventSchedule::bounds($startsAt, $endsAt, $timeZone, $this->clock->now(), true);
        $time = $before[0] !== $start->getTimestamp() || $before[1] !== $end?->getTimestamp();
        $placeChanged = $before[2] !== $place || $before[3] !== $street;
        $linkChanged = $before[4] !== $url;
        $event->revise($title, $description, $format, $place, $street, $url, $start, $end, $this->clock->now());
        $this->reminders->rescheduleAll($event);
        $type = self::changeType($time, $placeChanged, $linkChanged);
        if (null !== $type) {
            $this->notify($event, $actor, $type);
        }

        return $event;
    }

    public function cancel(ComunaEvent $event, User $actor): ComunaEvent
    {
        $this->em->lock($event, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        $this->assertOrganizer($event->getComuna(), $actor);
        if ($event->isCancelled()) {
            return $event;
        }
        $event->cancel($this->clock->now());
        $this->reminders->dropPending($event);
        $this->notify($event, $actor, NotificationType::EventCancelled);

        return $event;
    }

    public function respond(ComunaEvent $event, User $actor, AttendanceStatus $status): void
    {
        $this->em->lock($event, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        $this->assertReadable($event, $actor);
        if (!$actor->isEmailVerified()) {
            throw ApiProblem::emailNotVerified();
        }
        if ($event->getComuna()->isSuspended()) {
            throw ComunaModerationPolicy::suspended();
        }
        if ($event->getComuna()->isArchived()) {
            throw ComunaModerationPolicy::archived();
        }
        if ($event->isCancelled()) {
            throw ApiProblem::conflict('/errors/event-cancelled', 'Este evento foi cancelado.');
        }
        $now = $this->clock->now();
        if (EventSchedule::isPast($now, $event->getStartsAt(), $event->getEndsAt())) {
            throw ApiProblem::conflict('/errors/event-closed', 'Este evento já aconteceu.');
        }
        $positive = AttendanceStatus::NotGoing !== $status;
        if ($positive) {
            $this->gamification->prepare($actor, $now);
        }
        $row = $this->attendance->findOne($event, $actor);
        if (null === $row) {
            $row = new EventAttendance($event, $actor, $status, $now);
            $this->em->persist($row);
        } elseif ($row->getStatus() !== $status) {
            $row->replace($status, $now);
        }
        $this->reminders->sync($event, $actor, $status);
        if ($positive) {
            $this->gamification->record($actor, GamificationAction::EventRsvpPositive, $event->getId(), $now);
        }
    }

    private function assertOrganizer(Comuna $comuna, User $actor): void
    {
        if (!$actor->isActive()) {
            throw ApiProblem::accountInactive();
        }
        if (!$actor->isEmailVerified()) {
            throw ApiProblem::emailNotVerified();
        }
        $this->moderation->assertNotArchived($comuna);
        $this->moderation->assertModerator($comuna, $actor);
    }

    private function assertReadable(ComunaEvent $event, User $actor): void
    {
        if (!$actor->isActive()) {
            throw ApiProblem::accountInactive();
        }
        if (!$this->content->canReadComuna($event->getComuna(), $actor)) {
            throw ApiProblem::notFound('Evento não encontrado.');
        }
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private function fields(Comuna $comuna, EventFormat $format, string &$title, string &$description, ?string $placeName, ?string $address, ?string $onlineUrl): array
    {
        $title = TextNormalizer::normalize($title);
        $description = TextNormalizer::normalize($description);
        $place = $this->blank($placeName);
        $street = $this->blank($address);
        $url = $this->blank($onlineUrl);
        if ('' === $title || mb_strlen($title) > ComunaEvent::TITLE_MAX) {
            throw ApiProblem::badRequest('/errors/event-title', 'O título precisa ter entre 1 e 120 caracteres.');
        }
        if (mb_strlen($description) > ComunaEvent::DESCRIPTION_MAX) {
            throw ApiProblem::badRequest('/errors/event-description', 'A descrição passa de 2000 caracteres.');
        }
        $this->filter->assertText($title, $comuna);
        $this->filter->assertText($description, $comuna);
        if ($format->needsPlace()) {
            if (null === $place || mb_strlen($place) > ComunaEvent::PLACE_MAX) {
                throw ApiProblem::badRequest('/errors/event-place', 'Informe o nome do local.');
            }
            if (null !== $street && mb_strlen($street) > ComunaEvent::ADDRESS_MAX) {
                throw ApiProblem::badRequest('/errors/event-place', 'O endereço está longo demais.');
            }
        } elseif (null !== $place || null !== $street) {
            throw ApiProblem::badRequest('/errors/event-place', 'Evento online não leva local nem endereço.');
        }
        if ($format->needsUrl()) {
            $url = $this->httpsUrl($url);
        } elseif (null !== $url) {
            throw ApiProblem::badRequest('/errors/event-link', 'Evento presencial não leva link.');
        }
        if (!$format->needsPlace()) {
            $place = null;
            $street = null;
        }
        if (!$format->needsUrl()) {
            $url = null;
        }

        return [$place, $street, $url];
    }

    private function httpsUrl(?string $url): string
    {
        if (null === $url || strlen($url) > ComunaEvent::URL_MAX || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            throw ApiProblem::badRequest('/errors/event-link', 'Informe um link HTTPS válido.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || 'https' !== ($parts['scheme'] ?? '') || isset($parts['user']) || isset($parts['pass']) || !isset($parts['host']) || '' === $parts['host']) {
            throw ApiProblem::badRequest('/errors/event-link', 'O link precisa ser HTTPS, sem usuário nem senha.');
        }

        return $url;
    }

    private function blank(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = TextNormalizer::normalize($value);

        return '' === $value ? null : $value;
    }

    private function notify(ComunaEvent $event, User $actor, NotificationType $type): void
    {
        foreach ($this->attendance->interestedUsers($event) as $user) {
            $this->notifications->eventNotice($event, $user, $actor, $type);
        }
    }

    private static function changeType(bool $time, bool $place, bool $link): ?NotificationType
    {
        $count = (int) $time + (int) $place + (int) $link;

        return match ($count) {
            0 => null,
            1 => $time ? NotificationType::EventRescheduled : ($place ? NotificationType::EventPlaceChanged : NotificationType::EventLinkChanged),
            default => NotificationType::EventUpdated,
        };
    }
}
