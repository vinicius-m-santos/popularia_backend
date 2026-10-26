<?php

declare(strict_types=1);

namespace App\Api\Block;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Entity\UserBlock;
use App\Repository\UserBlockRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** @implements ProcessorInterface<mixed, null> */
final readonly class BlockProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserRepository $users,
        private UserBlockRepository $blocks,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $me = $this->currentUser->get();
        $target = $this->users->findOneByHandle((string) ($uriVariables['handle'] ?? '')) ?? throw ApiProblem::notFound('Perfil não encontrado.');
        if ($target->getId()->equals($me->getId())) {
            throw ApiProblem::badRequest('/errors/cannot-block-self', 'Não é possível bloquear a própria conta.');
        }

        if (null === $this->blocks->findBlock($me, $target)) {
            $this->em->persist(new UserBlock($me, $target, $this->clock->now()));
            $this->em->flush();
        }

        return null;
    }
}
