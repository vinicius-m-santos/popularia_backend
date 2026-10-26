<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EvidenceHold;
use App\Entity\SecurityEvent;
use App\Entity\User;
use App\Enum\SecurityEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SecurityEvent> */
class SecurityEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityEvent::class);
    }

    /** @return list<SecurityEvent> */
    public function findRecentForUser(User $user, int $limit): array
    {
        /** @var list<SecurityEvent> */
        return $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    public function countByType(SecurityEventType $type, ?User $user = null): int
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.type = :type')
            ->setParameter('type', $type);
        if (null !== $user) {
            $qb->andWhere('e.user = :user')->setParameter('user', $user);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** Apaga eventos anteriores a $before, exceto os de contas com preservação de evidências ativa. */
    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        $held = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(h.subject)')
            ->from(EvidenceHold::class, 'h')
            ->where('h.releasedAt IS NULL')
            ->getDQL();

        /** @var int */
        return $this->createQueryBuilder('e')
            ->delete()
            ->where('e.occurredAt < :before')
            ->andWhere(\sprintf('e.user IS NULL OR e.user NOT IN (%s)', $held))
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
