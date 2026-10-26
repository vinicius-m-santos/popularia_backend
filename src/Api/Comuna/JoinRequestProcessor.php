<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ComunaGovernance;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;

/**
 * PUT pede, DELETE retira o próprio pedido.
 *
 * @implements ProcessorInterface<mixed, JoinRequestView|null>
 */
final readonly class JoinRequestProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private ComunaGovernance $governance,
        private RateLimitGuard $rateLimit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?JoinRequestView
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $user = $this->currentUser->get();
        if ($operation instanceof Delete) {
            $this->governance->cancelJoinRequest($comuna, $user);

            return null;
        }
        $this->rateLimit->consume('comuna_join_request', $user->getUserIdentifier());

        return JoinRequestView::fromRequest($this->governance->requestToJoin($comuna, $user));
    }
}
