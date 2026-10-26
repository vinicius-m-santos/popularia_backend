<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comuna;
use App\Entity\ComunaMembership;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ComunaMembership> */
class ComunaMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComunaMembership::class);
    }

    public function findMembership(Comuna $comuna, ?User $user): ?ComunaMembership
    {
        return null === $user ? null : $this->findOneBy(['comuna' => $comuna, 'user' => $user]);
    }

    /**
     * Participações de $user entre as comunas dadas, indexadas pelo ID da comuna.
     *
     * @param list<Comuna> $comunas
     *
     * @return array<string, ComunaMembership>
     */
    public function findForComunas(User $user, array $comunas): array
    {
        if ([] === $comunas) {
            return [];
        }
        $memberships = $this->findBy(['user' => $user, 'comuna' => $comunas]);
        $byComuna = [];
        foreach ($memberships as $m) {
            $byComuna[$m->getComuna()->getId()->toRfc4122()] = $m;
        }

        return $byComuna;
    }
}
