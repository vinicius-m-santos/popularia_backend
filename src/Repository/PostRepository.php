<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comuna;
use App\Entity\ComunaMembership;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\ComunaVisibility;
use App\Enum\ContentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Consultas de publicações. Toda lista passa por visible(): só publicadas, de contas
 * ativas e sem bloqueio com quem vê. A paginação é por posição (keyset) no ID UUID v7,
 * estável mesmo com publicações novas chegando.
 *
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    /** @return list<Post> Até $limit + 1 itens. */
    public function findComunaPage(Comuna $comuna, ?User $viewer, ?Uuid $before, int $limit): array
    {
        $qb = $this->visible($viewer, $before, $limit)
            ->andWhere('p.comuna = :comuna')
            ->setParameter('comuna', $comuna);

        /** @var list<Post> */
        return $qb->getQuery()->getResult();
    }

    /**
     * "minhas": publicações das comunas de que a pessoa participa.
     * "para-voce": comunas públicas, comunas de que participa e publicações em perfis,
     * em ordem cronológica (regra simples e explicável; sem ranqueamento opaco).
     *
     * @return list<Post> até $limit + 1 itens
     */
    public function findFeedPage(User $viewer, string $tab, ?Uuid $before, int $limit): array
    {
        $memberOf = \sprintf('SELECT IDENTITY(fm.comuna) FROM %s fm WHERE fm.user = :feedViewer', ComunaMembership::class);
        $qb = $this->visible($viewer, $before, $limit)->setParameter('feedViewer', $viewer);

        if ('minhas' === $tab) {
            $qb->andWhere("c.id IN ($memberOf)");
        } else {
            $qb->andWhere("c.id IS NULL OR c.visibility = :public OR c.id IN ($memberOf)")
                ->setParameter('public', ComunaVisibility::Public);
        }

        /** @var list<Post> */
        return $qb->getQuery()->getResult();
    }

    public function incrementCommentCount(Post $post): void
    {
        $this->createQueryBuilder('p')
            ->update()
            ->set('p.commentCount', 'p.commentCount + 1')
            ->where('p.id = :id')
            ->setParameter('id', $post->getId(), 'uuid')
            ->getQuery()
            ->execute();
        $this->getEntityManager()->refresh($post);
    }

    /** @return list<Post> */
    public function findAuthorPage(User $author, ?User $viewer, ?Uuid $before, int $limit): array
    {
        $memberOf = sprintf('SELECT IDENTITY(pm.comuna) FROM %s pm WHERE pm.user = :profileViewer', ComunaMembership::class);
        $qb = $this->visible($viewer, $before, $limit)->andWhere('p.author = :profileAuthor')->setParameter('profileAuthor', $author);
        if (null === $viewer) {
            $qb->andWhere('c.id IS NULL OR c.visibility = :profilePublic');
        } else {
            $qb->andWhere("c.id IS NULL OR c.visibility = :profilePublic OR c.id IN ($memberOf)")->setParameter('profileViewer', $viewer);
        }
        $qb->setParameter('profilePublic', ComunaVisibility::Public);

        /** @var list<Post> */
        return $qb->getQuery()->getResult();
    }

    private function visible(?User $viewer, ?Uuid $before, int $limit): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('a', 'c', 'ld', 'lf', 'lc', 'lu')
            ->leftJoin('p.libraryDocument', 'ld')->leftJoin('ld.file', 'lf')->leftJoin('ld.collection', 'lc')->leftJoin('lf.uploadedBy', 'lu')->addSelect('lou', 'loc')->leftJoin('ld.user', 'lou')->leftJoin('ld.comuna', 'loc')
            ->join('p.author', 'a')
            ->leftJoin('p.comuna', 'c')
            ->where('p.status = :published')
            ->andWhere('a.status = :active')
            ->setParameter('published', ContentStatus::Published)
            ->setParameter('active', AccountStatus::Active)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit + 1);
        if (null === $viewer) {
            $qb->andWhere('c.id IS NOT NULL OR a.isPrivate = false');
        } else {
            $qb->andWhere('c.id IS NOT NULL OR a.isPrivate = false OR a = :privacyViewer OR EXISTS (SELECT pf.id FROM \App\Entity\UserFollow pf WHERE pf.follower = :privacyViewer AND pf.followed = a AND pf.approved = true)')->setParameter('privacyViewer', $viewer);
        }
        if (null !== $before) {
            $qb->andWhere('p.id < :before')->setParameter('before', $before, 'uuid');
        }
        if (null !== $viewer) {
            UserBlockRepository::excludeBlocked($qb, 'a', $viewer);
        }

        return $qb;
    }
}
