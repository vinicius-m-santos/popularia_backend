<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserBlock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UserBlock> */
class UserBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserBlock::class);
    }

    public function findBlock(User $blocker, User $blocked): ?UserBlock
    {
        return $this->findOneBy(['blocker' => $blocker, 'blocked' => $blocked]);
    }

    public function isBlockedEitherWay(?User $a, User $b): bool
    {
        if (null === $a || $a->getId()->equals($b->getId())) {
            return false;
        }

        return 0 < (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('(b.blocker = :a AND b.blocked = :b) OR (b.blocker = :b AND b.blocked = :a)')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array<string, true> IDs (RFC 4122) de quem bloqueou $user ou foi bloqueado por ele/ela. */
    public function blockedEitherWayIds(?User $user): array
    {
        if (null === $user) {
            return [];
        }
        /** @var list<array{blocker: string, blocked: string}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.blocker) AS blocker', 'IDENTITY(b.blocked) AS blocked')
            ->where('b.blocker = :u OR b.blocked = :u')
            ->setParameter('u', $user)
            ->getQuery()
            ->getArrayResult();

        $self = $user->getId()->toRfc4122();
        $ids = [];
        foreach ($rows as $row) {
            $other = (string) ($row['blocker'] === $self ? $row['blocked'] : $row['blocker']);
            $ids[$other] = true;
        }

        return $ids;
    }

    /** @return list<UserBlock> */
    public function findByBlocker(User $user): array
    {
        return $this->findBy(['blocker' => $user], ['createdAt' => 'DESC']);
    }

    /**
     * Restringe $qb a autores sem bloqueio com $viewer, em qualquer sentido.
     * $authorAlias é o alias da entidade User do autor na consulta.
     */
    public static function excludeBlocked(QueryBuilder $qb, string $authorAlias, User $viewer): void
    {
        $qb->andWhere(\sprintf(
            'NOT EXISTS (SELECT 1 FROM %1$s blk WHERE (blk.blocker = :blockViewer AND blk.blocked = %2$s) OR (blk.blocker = %2$s AND blk.blocked = :blockViewer))',
            UserBlock::class,
            $authorAlias,
        ))->setParameter('blockViewer', $viewer);
    }
}
