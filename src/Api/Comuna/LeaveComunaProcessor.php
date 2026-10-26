<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ComunaGovernance;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;

/** @implements ProcessorInterface<mixed, null> */
final readonly class LeaveComunaProcessor implements ProcessorInterface
{
    public function __construct(
        private ComunaRepository $comunas,
        private ComunaGovernance $governance,
        private CurrentUser $currentUser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $this->governance->leave($comuna, $this->currentUser->get());

        return null;
    }
}
