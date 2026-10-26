<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Pagination;
use App\Entity\ComunaMembership;
use App\Repository\ComunaMembershipRepository;
use App\Security\CurrentUser;

/**
 * Comunas de que a pessoa logada participa, com o papel dela em cada uma.
 *
 * @implements ProviderInterface<ComunaPage>
 */
final readonly class MyComunasProvider implements ProviderInterface
{
    public function __construct(
        private ComunaMembershipRepository $memberships,
        private ComunaViews $views,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ComunaPage
    {
        $me = $this->currentUser->get();
        $page = Pagination::fromContext($context);
        [$rows, $next] = $page->slice($this->memberships->findUserPage($me, $page->cursor, $page->limit), static fn (ComunaMembership $m) => $m->getId());

        $comunas = array_map(static fn (ComunaMembership $m) => $m->getComuna(), $rows);
        $mine = [];
        foreach ($rows as $m) {
            $mine[$m->getComuna()->getId()->toRfc4122()] = $m;
        }

        return new ComunaPage($this->views->many($comunas, $me, $mine), $next);
    }
}
