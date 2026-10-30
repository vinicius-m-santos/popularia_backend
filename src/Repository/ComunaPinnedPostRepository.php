<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comuna;
use App\Entity\ComunaPinnedPost;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\ContentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ComunaPinnedPost> */
class ComunaPinnedPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private PostRepository $posts)
    {
        parent::__construct($registry, ComunaPinnedPost::class);
    }

    /** @return list<ComunaPinnedPost> Ordem explícita (posição, depois data do pin). */
    public function ordered(Comuna $comuna): array
    {
        /** @var list<ComunaPinnedPost> $pins */
        $pins = $this->createQueryBuilder('pin')
            ->addSelect('p', 'a')
            ->join('pin.post', 'p')
            ->join('p.author', 'a')
            ->where('pin.comuna = :comuna')
            ->setParameter('comuna', $comuna)
            ->orderBy('pin.position', 'ASC')
            ->addOrderBy('pin.pinnedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $pins;
    }

    public function findInComuna(Comuna $comuna, string $postId): ?ComunaPinnedPost
    {
        if (!Uuid::isValid($postId)) {
            return null;
        }

        return $this->findOneBy(['comuna' => $comuna, 'post' => Uuid::fromString($postId)]);
    }

    /** Publicação publicada de conta ativa, ainda desta comuna, que ocupa uma vaga indevida. */
    public function hasIneligible(Comuna $comuna): bool
    {
        $count = $this->createQueryBuilder('pin')
            ->select('COUNT(pin.id)')
            ->join('pin.post', 'p')
            ->join('p.author', 'a')
            ->where('pin.comuna = :comuna')
            ->andWhere('p.status != :published OR a.status != :active OR p.comuna != :comuna')
            ->setParameter('comuna', $comuna)
            ->setParameter('published', ContentStatus::Published)
            ->setParameter('active', AccountStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * Até 3 publicações visíveis para quem vê, na ordem do pin. Mesma regra de leitura
     * das outras listas (ContentPolicy / PostRepository::applyVisibility): removida,
     * apagada, autoria suspensa ou bloqueio não aparece. O pin não abre comuna privada.
     *
     * @return list<Post>
     */
    public function findVisible(Comuna $comuna, ?User $viewer): array
    {
        $qb = $this->createQueryBuilder('pin')
            ->addSelect('p', 'a', 'c', 'ld', 'lf', 'lc', 'lu', 'lou', 'loc', 'pmedia', 'poll', 'pollOption')
            ->join('pin.post', 'p')
            ->join('p.author', 'a')
            ->join('p.comuna', 'c')
            ->leftJoin('p.libraryDocument', 'ld')->leftJoin('ld.file', 'lf')->leftJoin('ld.collection', 'lc')->leftJoin('lf.uploadedBy', 'lu')->leftJoin('ld.user', 'lou')->leftJoin('ld.comuna', 'loc')
            ->leftJoin('p.media', 'pmedia')
            ->leftJoin('p.poll', 'poll')->leftJoin('poll.options', 'pollOption')
            ->where('pin.comuna = :comuna')
            ->andWhere('p.comuna = :comuna')
            ->setParameter('comuna', $comuna)
            ->orderBy('pin.position', 'ASC')
            ->addOrderBy('pin.pinnedAt', 'ASC')
            ->addOrderBy('pollOption.position', 'ASC')
            ->setMaxResults(ComunaPinnedPost::MAX_PER_COMUNA);
        $this->posts->applyVisibility($qb, 'p', 'a', 'c', $viewer);

        /** @var list<ComunaPinnedPost> $pins */
        $pins = $qb->getQuery()->getResult();

        return array_map(static fn (ComunaPinnedPost $pin): Post => $pin->getPost(), $pins);
    }

    /**
     * @param list<Post> $posts
     *
     * @return array<string, true> IDs de publicações que estão fixadas em alguma comuna
     */
    public function pinnedPostIds(array $posts): array
    {
        if ([] === $posts) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->createQueryBuilder('pin')
            ->select('IDENTITY(pin.post)')
            ->where('pin.post IN (:ids)')
            ->setParameter('ids', array_map(static fn (Post $post): Uuid => $post->getId(), $posts))
            ->getQuery()
            ->getSingleColumnResult();

        $flags = [];
        foreach ($ids as $id) {
            $flags[(string) $id] = true;
        }

        return $flags;
    }
}
