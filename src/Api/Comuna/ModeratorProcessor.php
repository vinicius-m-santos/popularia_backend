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
 * PUT nomeia, DELETE tira da moderação.
 *
 * @implements ProcessorInterface<mixed, ComunaMemberView|null>
 */
final readonly class ModeratorProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private UserRepository $users,
        private ComunaGovernance $governance,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ComunaMemberView
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $target = $this->users->findOneByHandle((string) ($uriVariables['handle'] ?? '')) ?? throw ApiProblem::notFound('Perfil não encontrado.');
        $appoint = !$operation instanceof Delete;
        $membership = $this->governance->setModerator($comuna, $this->currentUser->get(), $target, $appoint);

        return $appoint ? ComunaMemberView::fromMembership($membership) : null;
    }
}
