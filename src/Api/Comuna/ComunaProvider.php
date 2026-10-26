<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<ComunaView> */
final readonly class ComunaProvider implements ProviderInterface
{
    public function __construct(
        private ComunaRepository $comunas,
        private ComunaViews $views,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ComunaView
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');

        return $this->views->detail($comuna, $this->currentUser->find());
    }
}
