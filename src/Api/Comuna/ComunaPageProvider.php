<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Pagination;
use App\Entity\Comuna;
use App\Repository\ComunaMembershipRepository;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<ComunaPage> */
final readonly class ComunaPageProvider implements ProviderInterface
{
    public function __construct(
        private ComunaRepository $comunas,
        private ComunaMembershipRepository $memberships,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ComunaPage
    {
        $page = Pagination::fromContext($context);
        [$comunas, $next] = $page->slice($this->comunas->findPage($page->cursor, $page->limit), static fn (Comuna $c) => $c->getId());

        $viewer = $this->currentUser->find();
        $mine = null === $viewer ? [] : $this->memberships->findForComunas($viewer, $comunas);

        return new ComunaPage(
            array_map(static fn (Comuna $c) => ComunaView::fromComuna($c, $mine[$c->getId()->toRfc4122()] ?? null), $comunas),
            $next,
        );
    }
}
