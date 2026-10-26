<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ComunaGovernance;
use App\Repository\ComunaRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;

/** @implements ProcessorInterface<mixed, null> */
final readonly class MemberRemoveProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private UserRepository $users,
        private ComunaGovernance $governance,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $target = $this->users->findOneByHandle((string) ($uriVariables['handle'] ?? '')) ?? throw ApiProblem::notFound('Perfil não encontrado.');
        $this->governance->removeMember($comuna, $this->currentUser->get(), $target);

        return null;
    }
}
