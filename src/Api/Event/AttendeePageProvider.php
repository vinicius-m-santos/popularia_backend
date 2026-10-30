<?php

declare(strict_types=1);

namespace App\Api\Event;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Api\Profile\AuthorView;
use App\Content\ContentPolicy;
use App\Entity\ComunaEvent;
use App\Enum\AttendanceStatus;
use App\Repository\ComunaEventRepository;
use App\Repository\EventAttendanceRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<AttendeePage> */
final readonly class AttendeePageProvider implements ProviderInterface
{
    public function __construct(
        private ComunaEventRepository $events,
        private EventAttendanceRepository $attendance,
        private ContentPolicy $content,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AttendeePage
    {
        $id = (string) ($uriVariables['id'] ?? '');
        $event = \Symfony\Component\Uid\Uuid::isValid($id) ? $this->events->find(\Symfony\Component\Uid\Uuid::fromString($id)) : null;
        $viewer = $this->currentUser->get();
        if (!$event instanceof ComunaEvent || !$this->content->canReadComuna($event->getComuna(), $viewer)) {
            throw ApiProblem::notFound('Evento não encontrado.');
        }
        $request = $context['request'] ?? null;
        $status = $request instanceof \Symfony\Component\HttpFoundation\Request ? (string) $request->query->get('status', '') : '';
        $attendance = AttendanceStatus::tryFrom($status);
        if (null === $attendance || AttendanceStatus::NotGoing === $attendance) {
            throw ApiProblem::badRequest('/errors/invalid-attendance', 'Liste apenas quem vai ou talvez vá.');
        }
        $page = Pagination::fromContext($context);
        $rows = $this->attendance->findAttendeePage($event, $attendance, $viewer, $page->cursor, $page->limit);
        [$items, $next] = $page->slice($rows, static fn ($row) => $row->getId());

        return new AttendeePage(array_map(static fn ($row) => AuthorView::fromUser($row->getUser()), $items), $next);
    }
}
