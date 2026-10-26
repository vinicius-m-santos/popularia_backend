<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Enum\ComunaRole;
use App\Repository\ComunaMembershipRepository;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/** @implements ProcessorInterface<mixed, null> */
final readonly class LeaveComunaProcessor implements ProcessorInterface
{
    public function __construct(
        private ComunaRepository $comunas,
        private ComunaMembershipRepository $memberships,
        private CurrentUser $currentUser,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $membership = $this->memberships->findMembership($comuna, $this->currentUser->get());
        if (null === $membership) {
            return null;
        }
        if (ComunaRole::Founder === $membership->getRole()) {
            throw ApiProblem::conflict('/errors/founder-cannot-leave', 'Quem fundou a comuna não pode sair por aqui.', 'Passe a responsabilidade para outra pessoa da moderação antes.');
        }

        $this->em->wrapInTransaction(function () use ($membership, $comuna): void {
            $this->em->remove($membership);
            $this->em->flush();
            $this->comunas->adjustMemberCount($comuna, -1);
        });

        return null;
    }
}
