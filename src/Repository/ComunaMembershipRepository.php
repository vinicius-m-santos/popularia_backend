<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comuna;
use App\Entity\ComunaMembership;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\ComunaRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    /** Membros além de $owner, contados no banco (a exclusão definitiva confere isto sob trava). */
    public function countOthers(Comuna $comuna, User $owner): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.comuna = :comuna')
            ->andWhere('m.user <> :owner')
            ->setParameter('comuna', $comuna)
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Membros com conta ativa, na ordem de entrada (ID UUID v7).
     *
     * @return list<ComunaMembership> até $limit + 1 itens
     */
    public function findMemberPage(Comuna $comuna, ?Uuid $after, int $limit): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->where('m.comuna = :comuna')
            ->andWhere('u.status = :active')
            ->setParameter('comuna', $comuna)
            ->setParameter('active', AccountStatus::Active)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit + 1);
        if (null !== $after) {
            $qb->andWhere('m.id > :after')->setParameter('after', $after, 'uuid');
        }

        /** @var list<ComunaMembership> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Quem responde pela comuna (proprietário e moderação), dado público da comuna.
     *
     * @return list<ComunaMembership> proprietário primeiro
     */
    public function findModeration(Comuna $comuna): array
    {
        /** @var list<ComunaMembership> $rows */
        $rows = $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->where('m.comuna = :comuna')
            ->andWhere('m.role IN (:roles)')
            ->andWhere('u.status = :active')
            ->setParameter('comuna', $comuna)
            ->setParameter('roles', [ComunaRole::Founder, ComunaRole::Moderator])
            ->setParameter('active', AccountStatus::Active)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
        usort($rows, static fn (ComunaMembership $a, ComunaMembership $b) => (ComunaRole::Founder === $b->getRole()) <=> (ComunaRole::Founder === $a->getRole()));

        return $rows;
    }

    /**
     * Comunas de que $user participa, na ordem de entrada.
     *
     * @return list<ComunaMembership> até $limit + 1 itens
     */
    public function findUserPage(User $user, ?Uuid $after, int $limit): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('c')
            ->join('m.comuna', 'c')
            ->where('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit + 1);
        if (null !== $after) {
            $qb->andWhere('m.id > :after')->setParameter('after', $after, 'uuid');
        }

        /** @var list<ComunaMembership> */
        return $qb->getQuery()->getResult();
    }
}
