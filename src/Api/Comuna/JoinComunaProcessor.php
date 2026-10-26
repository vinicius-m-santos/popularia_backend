<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Entity\ComunaMembership;
use App\Enum\ComunaRole;
use App\Repository\ComunaMembershipRepository;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** @implements ProcessorInterface<mixed, MembershipView> */
final readonly class JoinComunaProcessor implements ProcessorInterface
{
    public function __construct(
        private ComunaRepository $comunas,
        private ComunaMembershipRepository $memberships,
        private CurrentUser $currentUser,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MembershipView
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $user = $this->currentUser->get();

        $membership = $this->memberships->findMembership($comuna, $user);
        if (null === $membership) {
            if (!$comuna->isPublic()) {
                throw ApiProblem::forbidden('/errors/comuna-requires-approval', 'Esta comuna é privada.', 'A entrada depende de convite ou aprovação da moderação.');
            }
            $membership = new ComunaMembership($comuna, $user, ComunaRole::Member, $this->clock->now());
            $this->em->wrapInTransaction(function () use ($membership, $comuna): void {
                $this->em->persist($membership);
                $this->em->flush();
                $this->comunas->adjustMemberCount($comuna, +1);
            });
        }

        return new MembershipView($comuna->getSlug(), true, $membership->getRole()->value, $comuna->getMemberCount());
    }
}
