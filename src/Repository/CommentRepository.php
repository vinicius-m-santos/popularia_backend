<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Comment> */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * Comentários em ordem cronológica, inclusive os apagados e os de pessoas bloqueadas:
     * eles viram marcadores sem texto, para as respostas não perderem o contexto.
     *
     * @return list<Comment> até $limit + 1 itens
     */
    public function findPostPage(Post $post, ?Uuid $after, int $limit): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('a')
            ->join('m.author', 'a')
            ->where('m.post = :post')
            ->setParameter('post', $post)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit + 1);
        if (null !== $after) {
            $qb->andWhere('m.id > :after')->setParameter('after', $after, 'uuid');
        }

        /** @var list<Comment> */
        return $qb->getQuery()->getResult();
    }
}
