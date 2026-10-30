<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comuna;
use App\Entity\ComunaEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ComunaEvent> */
class ComunaEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComunaEvent::class);
    }

    /** @return list<ComunaEvent> */
    public function findPage(Comuna $comuna, string $when, ?\DateTimeImmutable $cursorAt, ?Uuid $cursorId, int $limit, \DateTimeImmutable $now): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.comuna = :comuna')
            ->setParameter('comuna', $comuna)
            ->setParameter('now', $now)
            ->setMaxResults($limit + 1);
        $boundary = 'COALESCE(e.endsAt, e.startsAt)';
        if ('past' === $when) {
            $qb->andWhere($boundary.' < :now')->orderBy('e.startsAt', 'DESC')->addOrderBy('e.id', 'DESC');
            if (null !== $cursorAt && null !== $cursorId) {
                $qb->andWhere('e.startsAt < :cursorAt OR (e.startsAt = :cursorAt AND e.id < :cursorId)');
            }
        } else {
            $qb->andWhere($boundary.' >= :now')->orderBy('e.startsAt', 'ASC')->addOrderBy('e.id', 'ASC');
            if (null !== $cursorAt && null !== $cursorId) {
                $qb->andWhere('e.startsAt > :cursorAt OR (e.startsAt = :cursorAt AND e.id > :cursorId)');
            }
        }
        if (null !== $cursorAt && null !== $cursorId) {
            $qb->setParameter('cursorAt', $cursorAt)->setParameter('cursorId', $cursorId, 'uuid');
        }

        /** @var list<ComunaEvent> */
        return $qb->getQuery()->getResult();
    }
}
