<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ComunaGovernance;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;

/** @implements ProcessorInterface<ComunaUpdateInput, ComunaView> */
final readonly class ComunaUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private ComunaGovernance $governance,
        private ComunaViews $views,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ComunaView
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $actor = $this->currentUser->get();
        $rules = null === $data->rules ? null : array_map(static fn (ComunaRuleInput $r) => $r->toArray(), $data->rules);
        $this->governance->updateDetails($comuna, $actor, $data->description, $rules);

        return $this->views->detail($comuna, $actor);
    }
}
