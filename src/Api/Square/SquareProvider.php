<?php

declare(strict_types=1);

namespace App\Api\Square;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Entity\ContentReport;
use App\Entity\Square;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;
use App\Square\SquareAccess;
use App\Square\SquareQueries;
use App\Square\SquareService;
use App\Square\SquareViews;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<SquareView|SquarePage|MessagePage|PresenceView|StickerPage|ReportPage> */
final readonly class SquareProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private SquareAccess $access,
        private SquareViews $views,
        private SquareQueries $queries,
        private SquareService $service,
        private EntityManagerInterface $em,
        private ComunaRepository $comunas,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SquareView|SquarePage|MessagePage|PresenceView|StickerPage|ReportPage
    {
        $viewer = $this->currentUser->get();
        $page = Pagination::fromContext($context);
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query : null;
        $name = $operation->getName();
        if ('square_list' === $name) {
            $section = (string) $query?->get('section', 'discover');
            if (!in_array($section, ['mine', 'discover'], true)) {
                throw ApiProblem::badRequest('/errors/invalid-section', 'Seção inválida.');
            }
            $rows = $this->queries->list($viewer, 'mine' === $section, $page->cursor, $page->limit + 1);
            [$items, $next] = $page->slice($rows, static fn (Square $s) => $s->id);

            return new SquarePage($this->views->squares($items, $viewer), $next);
        }
        if ('square_reports' === $name) {
            $comuna = $this->comunas->findOneBySlug((string) $uriVariables['slug']) ?? throw ApiProblem::notFound();
            $this->access->moderation->assertModerator($comuna, $viewer);
            $qb = $this->em->createQueryBuilder()->select('r')->from(ContentReport::class, 'r')->where("r.comuna = :comuna AND r.targetType = 'square_message'")->andWhere('r.status = :status')->setParameter('comuna', $comuna)->setParameter('status', 'pending')->orderBy('r.id', 'DESC')->setMaxResults($page->limit + 1);
            if (null !== $page->cursor) {
                $qb->andWhere('r.id < :cursor')->setParameter('cursor', $page->cursor, 'uuid');
            }
            /** @var list<ContentReport> $rows */
            $rows = $qb->getQuery()->getResult();
            [$reports, $next] = $page->slice($rows, static fn (ContentReport $r) => $r->id);
            $messages = $this->queries->latestByIds(array_map(static fn (ContentReport $r) => $r->targetId->toRfc4122(), $reports));
            $byId = [];
            foreach ($this->views->messages($messages, $viewer) as $message) {
                $byId[$message->id] = $message;
            }

            return new ReportPage(array_map(static fn (ContentReport $r) => ['id' => $r->id->toRfc4122(), 'reason' => isset($byId[$r->targetId->toRfc4122()]) && 'published' === $byId[$r->targetId->toRfc4122()]->status ? $r->reason : 'Mensagem indisponível', 'createdAt' => $r->createdAt, 'message' => $byId[$r->targetId->toRfc4122()] ?? null], $reports), $next);
        }
        if ('square_comuna' === $name) {
            $comuna = $this->comunas->findOneBySlug((string) $uriVariables['slug']) ?? throw ApiProblem::notFound();
            $square = $this->em->getRepository(Square::class)->findOneBy(['comuna' => $comuna]) ?? throw ApiProblem::notFound('Praça indisponível.');
        } else {
            $square = $this->access->find((string) $uriVariables['id']);
        }
        if (in_array($name, ['square_detail', 'square_comuna'], true)) {
            return $this->views->squares([$square], $viewer, true)[0];
        }
        $this->access->read($square, $viewer);
        if ('square_stickers' === $name) {
            return new StickerPage($this->service->packs($square, $viewer));
        }
        if ('square_presence' === $name) {
            return new PresenceView($this->queries->stats([$square], $viewer)[$square->id->toRfc4122()]['presence'], $this->queries->presence($square, $viewer));
        }
        $afterValue = $query?->get('after');
        $after = null;
        if (null !== $afterValue) {
            if (!ctype_digit($afterValue) || strlen($afterValue) > 15 || null !== $page->cursor) {
                throw ApiProblem::badRequest('/errors/invalid-cursor', 'Cursor de alterações inválido.');
            }
            $after = (int) $afterValue;
        }
        $refresh = (string) $query?->get('refreshIds', '');
        $ids = '' === $refresh ? [] : explode(',', $refresh);
        if (count($ids) > 200 || count(array_filter($ids, static fn (string $id) => !Uuid::isValid($id))) > 0) {
            throw ApiProblem::badRequest('/errors/invalid-ids', 'Lista de mensagens inválida.');
        }
        $rows = $this->queries->messages($square, $page->cursor, $page->limit, $after);
        $hasMore = count($rows) > $page->limit;
        $items = array_slice($rows, 0, $page->limit);
        $last = end($items);
        $cursor = null === $after ? $square->version : ($hasMore && false !== $last ? $last->revision : $square->version);
        // Nunca retroceder um cursor já observado.
        $cursor = max($cursor, $after ?? 0);
        $next = null === $after && $hasMore && false !== $last ? $last->id->toRfc4122() : null;

        return new MessagePage($this->views->messages($items, $viewer), $next, $cursor, null !== $after && $hasMore, $this->views->messages($this->queries->byIds($square, $ids), $viewer), $this->views->squares([$square], $viewer, true)[0]);
    }
}
