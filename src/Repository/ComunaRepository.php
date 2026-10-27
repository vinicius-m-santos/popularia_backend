<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Comuna;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\ComunaStatus;
use App\Enum\ContentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
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
            ->addSelect('o')
            ->leftJoin('c.owner', 'o')
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

    public function slugExists(string $slug): bool
    {
        return null !== $this->findOneBySlug($slug);
    }

    /**
     * Relê a comuna com SELECT ... FOR UPDATE (precisa de transação aberta). Operações que mudam
     * participação, estado ou propriedade passam por aqui, então ficam em fila por comuna: uma
     * exclusão não corre ao mesmo tempo que uma entrada nova, por exemplo.
     */
    public function lock(Comuna $comuna): void
    {
        $this->getEntityManager()->refresh($comuna, LockMode::PESSIMISTIC_WRITE);
    }

    public function countActiveOwnedBy(User $owner): int
    {
        return $this->count(['owner' => $owner, 'status' => ComunaStatus::Active]);
    }

    /**
     * Conteúdo que impede a exclusão definitiva: publicações ou comentários de outras pessoas
     * (que não foram apagados pela própria autoria) e qualquer conteúdo removido pela moderação
     * (registro de decisão). O que a pessoa proprietária escreveu sai junto com a comuna.
     */
    public function hasContentBlockingDeletion(Comuna $comuna, User $owner): bool
    {
        $em = $this->getEntityManager();
        $queries = [
            $em->createQueryBuilder()->select('1')->from(Post::class, 'x')->where('x.comuna = :comuna'),
            $em->createQueryBuilder()->select('1')->from(\App\Entity\SquareMessage::class, 'x')->join('x.square', 'sq')->where('sq.comuna = :comuna'),
            $em->createQueryBuilder()->select('1')->from(Comment::class, 'x')->join('x.post', 'p')->where('p.comuna = :comuna'),
        ];
        foreach ($queries as $qb) {
            $found = $qb->andWhere('(x.author <> :owner AND x.status <> :deleted) OR x.status = :removed')
                ->setParameter('comuna', $comuna)
                ->setParameter('owner', $owner)
                ->setParameter('deleted', ContentStatus::Deleted)
                ->setParameter('removed', ContentStatus::Removed)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if (null !== $found) {
                return true;
            }
        }

        return false;
    }
}
