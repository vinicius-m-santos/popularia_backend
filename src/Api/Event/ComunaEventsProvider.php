<?php

declare(strict_types=1);

namespace App\Api\Event;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Content\ContentPolicy;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<EventPage> */
final readonly class ComunaEventsProvider implements ProviderInterface
{
    public function __construct(
        private ComunaRepository $comunas,
        private \App\Repository\ComunaEventRepository $events,
        private ContentPolicy $content,
        private EventViews $views,
        private CurrentUser $currentUser,
        private \Symfony\Component\Clock\ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): EventPage
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $viewer = $this->currentUser->find();
        $this->content->assertCanReadComuna($comuna, $viewer);
        $request = $context['request'] ?? null;
        if (!$request instanceof Request) {
            throw ApiProblem::badRequest('/errors/invalid-event-filter', 'Use upcoming ou past.');
        }
        $when = (string) $request->query->get('when', 'upcoming');
        if (!in_array($when, ['upcoming', 'past'], true)) {
            throw ApiProblem::badRequest('/errors/invalid-event-filter', 'Use upcoming ou past.');
        }
        $limit = self::limit($request->query->get('limit'));
        [$cursorAt, $cursorId] = self::cursor($request->query->get('cursor'));
        $rows = $this->events->findPage($comuna, $when, $cursorAt, $cursorId, $limit, $this->clock->now());
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = end($rows);
        $next = $hasMore && $last instanceof \App\Entity\ComunaEvent
            ? $last->getStartsAt()->format(\DATE_ATOM).'|'.$last->getId()->toRfc4122()
            : null;

        return new EventPage($this->views->many($rows, $viewer), $next);
    }

    private static function limit(mixed $raw): int
    {
        if (!\is_string($raw) || '' === $raw) {
            return Pagination::DEFAULT_LIMIT;
        }
        if (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > Pagination::MAX_LIMIT) {
            throw ApiProblem::badRequest('/errors/invalid-limit', \sprintf('O limite precisa estar entre 1 e %d.', Pagination::MAX_LIMIT));
        }

        return (int) $raw;
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?Uuid} */
    private static function cursor(mixed $raw): array
    {
        if (!\is_string($raw) || '' === $raw) {
            return [null, null];
        }
        $parts = explode('|', $raw, 2);
        if (2 !== count($parts) || !Uuid::isValid($parts[1])) {
            throw ApiProblem::badRequest('/errors/invalid-cursor', 'Cursor de paginação inválido.');
        }
        try {
            return [new \DateTimeImmutable($parts[0]), Uuid::fromString($parts[1])];
        } catch (\Exception) {
            throw ApiProblem::badRequest('/errors/invalid-cursor', 'Cursor de paginação inválido.');
        }
    }
}
