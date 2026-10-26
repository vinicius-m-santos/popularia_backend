<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Content\ComunaModerationPolicy;
use App\Entity\ComunaJoinRequest;
use App\Repository\ComunaJoinRequestRepository;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<JoinRequestPage> */
final readonly class JoinRequestPageProvider implements ProviderInterface
{
    public function __construct(
        private ComunaRepository $comunas,
        private ComunaJoinRequestRepository $requests,
        private ComunaModerationPolicy $policy,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JoinRequestPage
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $this->policy->assertModerator($comuna, $this->currentUser->get());

        $page = Pagination::fromContext($context);
        [$rows, $next] = $page->slice($this->requests->findPendingPage($comuna, $page->cursor, $page->limit), static fn (ComunaJoinRequest $r) => $r->getId());

        return new JoinRequestPage(array_map(JoinRequestView::fromRequest(...), $rows), $next);
    }
}
