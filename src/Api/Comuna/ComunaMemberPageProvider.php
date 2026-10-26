<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Entity\ComunaMembership;
use App\Repository\ComunaMembershipRepository;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<ComunaMemberPage> */
final readonly class ComunaMemberPageProvider implements ProviderInterface
{
    public function __construct(
        private ComunaRepository $comunas,
        private ComunaMembershipRepository $memberships,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ComunaMemberPage
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        if (null === $this->memberships->findMembership($comuna, $this->currentUser->get())) {
            throw ApiProblem::forbidden('/errors/members-only', 'Só quem participa vê a lista de membros.');
        }

        $page = Pagination::fromContext($context);
        [$rows, $next] = $page->slice($this->memberships->findMemberPage($comuna, $page->cursor, $page->limit), static fn (ComunaMembership $m) => $m->getId());

        return new ComunaMemberPage(array_map(ComunaMemberView::fromMembership(...), $rows), $next);
    }
}
