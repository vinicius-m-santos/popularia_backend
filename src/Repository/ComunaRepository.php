<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comuna;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Comuna> */
class ComunaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comuna::class);
    }

    public function findOneBySlug(string $slug): ?Comuna
    {
        return $this->findOneBy(['slug' => mb_strtolower($slug)]);
    }

    /** @return list<Comuna> Até $limit + 1 itens, para saber se há próxima página. */
    public function findPage(?Uuid $after, int $limit): array
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.id', 'ASC')
            ->setMaxResults($limit + 1);
        if (null !== $after) {
            $qb->where('c.id > :after')->setParameter('after', $after, 'uuid');
        }

        /** @var list<Comuna> */
        return $qb->getQuery()->getResult();
    }

    /** Soma ou subtrai do total de membros numa única instrução (sem corrida entre requisições). */
    public function adjustMemberCount(Comuna $comuna, int $delta): void
    {
        $this->createQueryBuilder('c')
            ->update()
            ->set('c.memberCount', 'c.memberCount + :delta')
            ->where('c.id = :id')
            ->setParameter('delta', $delta)
            ->setParameter('id', $comuna->getId(), 'uuid')
            ->getQuery()
            ->execute();
        $this->getEntityManager()->refresh($comuna);
    }
}
