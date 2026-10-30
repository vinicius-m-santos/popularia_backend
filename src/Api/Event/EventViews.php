<?php

declare(strict_types=1);

namespace App\Api\Event;

use App\Api\Profile\AuthorView;
use App\Content\ContentPolicy;
use App\Content\EventSchedule;
use App\Entity\ComunaEvent;
use App\Entity\User;
use App\Repository\ComunaMembershipRepository;
use App\Repository\EventAttendanceRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class EventViews
{
    public function __construct(
        private EventAttendanceRepository $attendance,
        private ComunaMembershipRepository $memberships,
        private ContentPolicy $content,
        private ClockInterface $clock,
    ) {
    }

    public function one(ComunaEvent $event, ?User $viewer): EventView
    {
        return $this->many([$event], $viewer)[0];
    }

    /**
     * @param list<ComunaEvent> $events
     *
     * @return list<EventView>
     */
    public function many(array $events, ?User $viewer): array
    {
        $counts = $this->attendance->counts($events, $viewer);
        $mine = $this->attendance->viewerStatuses($events, $viewer);
        $now = $this->clock->now();
        $comunas = [];
        foreach ($events as $event) {
            $comunas[$event->getComuna()->getId()->toRfc4122()] = $event->getComuna();
        }
        $memberships = null === $viewer ? [] : $this->memberships->findForComunas($viewer, array_values($comunas));

        return array_map(function (ComunaEvent $event) use ($viewer, $counts, $mine, $now, $memberships): EventView {
            $id = $event->getId()->toRfc4122();
            $comuna = $event->getComuna();
            $membership = $memberships[$comuna->getId()->toRfc4122()] ?? null;
            $zone = EventSchedule::zone($event->getTimeZone());
            $past = EventSchedule::isPast($now, $event->getStartsAt(), $event->getEndsAt());
            $organizer = $event->getOrganizer();
            $canRead = $this->content->canReadComuna($comuna, $viewer);

            return new EventView(
                $id,
                $event->getTitle(),
                $event->getDescription(),
                $event->getFormat()->value,
                $event->getStatus()->value,
                $event->getTimeZone(),
                $event->getStartsAt()->setTimezone($zone),
                $event->getEndsAt()?->setTimezone($zone),
                $event->getPlaceName(),
                $event->getAddress(),
                $event->getOnlineUrl(),
                [
                    'id' => $comuna->getId()->toRfc4122(),
                    'slug' => $comuna->getSlug(),
                    'name' => $comuna->getName(),
                    'visibility' => $comuna->getVisibility()->value,
                    'status' => $comuna->getStatus()->value,
                ],
                null !== $organizer && $organizer->isActive() ? AuthorView::fromUser($organizer) : null,
                $counts[$id]['going'],
                $counts[$id]['maybe'],
                $mine[$id]->value ?? null,
                null !== $membership && $membership->getRole()->canModerate() && !$comuna->isArchived() && !$event->isCancelled(),
                null !== $viewer && $viewer->isActive() && $viewer->isEmailVerified() && $canRead && !$past && !$event->isCancelled() && !$comuna->isArchived(),
                $past,
                $event->getCreatedAt(),
                $event->getUpdatedAt(),
                $event->getCancelledAt(),
            );
        }, $events);
    }

    public function summary(ComunaEvent $event, ?User $viewer): EventSummary
    {
        $counts = $this->attendance->counts([$event], $viewer)[$event->getId()->toRfc4122()];
        $zone = EventSchedule::zone($event->getTimeZone());

        return new EventSummary(
            $event->getId()->toRfc4122(),
            $event->getTitle(),
            $event->getFormat()->value,
            $event->getStatus()->value,
            $event->getStartsAt()->setTimezone($zone),
            $event->getTimeZone(),
            $event->getPlaceName(),
            $counts['going'],
            $counts['maybe'],
            EventSchedule::isPast($this->clock->now(), $event->getStartsAt(), $event->getEndsAt()),
        );
    }
}
