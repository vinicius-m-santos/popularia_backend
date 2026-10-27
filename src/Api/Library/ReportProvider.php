<?php

declare(strict_types=1);

namespace App\Api\Library;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Content\ComunaModerationPolicy;
use App\Entity\ContentReport;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/** @implements ProviderInterface<ReportPage> */
final readonly class ReportProvider implements ProviderInterface
{
    public function __construct(private CurrentUser $currentUser, private EntityManagerInterface $em, private ComunaRepository $comunas, private ComunaModerationPolicy $moderation)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReportPage
    {
        $viewer = $this->currentUser->get();
        $request = $context['request'] ?? new Request();
        \assert($request instanceof Request);
        $slug = $request->query->getString('comuna');
        $qb = $this->em->createQueryBuilder()->select('r')->from(ContentReport::class, 'r')->where("r.targetType = 'library_document' AND r.status = 'pending'");
        if ('' !== $slug) {
            $comuna = $this->comunas->findOneBySlug($slug) ?? throw ApiProblem::notFound();
            $this->moderation->assertModerator($comuna, $viewer);
            $qb->andWhere('r.comuna = :comuna')->setParameter('comuna', $comuna);
        } elseif (!in_array($viewer->getPlatformRole()->value, ['admin', 'platform_moderator'], true)) {
            throw ApiProblem::forbidden('/errors/platform-moderation', 'Só a moderação da plataforma pode acessar esta fila.');
        }
        $page = Pagination::fromContext($context);
        if (null !== $page->cursor) {
            $qb->andWhere('r.id < :cursor')->setParameter('cursor', $page->cursor, 'uuid');
        }
        /** @var list<ContentReport> $rows */
        $rows = $qb->orderBy('r.id', 'DESC')->setMaxResults($page->limit + 1)->getQuery()->getResult();
        [$rows, $next] = $page->slice($rows, static fn (ContentReport $r) => $r->id);

        return new ReportPage(array_map(static fn (ContentReport $r) => ['id' => $r->id->toRfc4122(), 'documentId' => $r->targetId->toRfc4122(), 'reason' => $r->reason, 'createdAt' => $r->createdAt], $rows), $next);
    }
}
