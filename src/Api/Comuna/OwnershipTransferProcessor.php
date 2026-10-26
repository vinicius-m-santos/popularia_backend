<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ComunaGovernance;
use App\Repository\ComunaRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;

/**
 * PUT propõe, DELETE retira.
 *
 * @implements ProcessorInterface<OwnershipTransferInput|null, OwnershipTransferView|null>
 */
final readonly class OwnershipTransferProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private UserRepository $users,
        private ComunaGovernance $governance,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?OwnershipTransferView
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $actor = $this->currentUser->get();
        if ($operation instanceof Delete) {
            $this->governance->cancelTransfer($comuna, $actor);

            return null;
        }
        \assert($data instanceof OwnershipTransferInput);
        $target = $this->users->findOneByHandle($data->handle) ?? throw ApiProblem::notFound('Perfil não encontrado.');

        return OwnershipTransferView::fromTransfer($this->governance->proposeTransfer($comuna, $actor, $target));
    }
}
