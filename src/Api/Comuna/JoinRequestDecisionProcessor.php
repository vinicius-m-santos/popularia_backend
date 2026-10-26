<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ComunaGovernance;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;

/** @implements ProcessorInterface<mixed, JoinRequestView> */
final readonly class JoinRequestDecisionProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private ComunaGovernance $governance,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JoinRequestView
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $approve = $operation instanceof HttpOperation && str_ends_with((string) $operation->getUriTemplate(), '/approve');
        $request = $this->governance->decideJoinRequest($comuna, $this->currentUser->get(), (string) ($uriVariables['id'] ?? ''), $approve);

        return JoinRequestView::fromRequest($request);
    }
}
