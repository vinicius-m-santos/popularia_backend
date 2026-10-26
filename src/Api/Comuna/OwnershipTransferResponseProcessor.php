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

/** @implements ProcessorInterface<mixed, OwnershipTransferView> */
final readonly class OwnershipTransferResponseProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private ComunaGovernance $governance,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OwnershipTransferView
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $accept = $operation instanceof HttpOperation && str_ends_with((string) $operation->getUriTemplate(), '/accept');

        return OwnershipTransferView::fromTransfer($this->governance->respondToTransfer($comuna, $this->currentUser->get(), $accept));
    }
}
