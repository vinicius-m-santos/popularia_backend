<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComunaMembership;
use App\Entity\Post;
use App\Entity\SavedPost;
use App\Entity\User;
use App\Enum\ComunaVisibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SavedPost>
 */
class SavedPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private PostRepository $posts)
    {
        parent::__construct($registry, SavedPost::class);
    }

    public function save(User $user, Post $post, \DateTimeImmutable $now): bool
    {
        $inserted = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO saved_post (id, user_id, post_id, created_at) VALUES (:id, :user, :post, :at) ON CONFLICT (user_id, post_id) DO NOTHING',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'user' => $user->getId()->toRfc4122(),
                'post' => $post->getId()->toRfc4122(),
                'at' => $now->format('Y-m-d H:i:sP'),
            ],
        );

        return $inserted > 0;
    }

    public function unsave(User $user, string $postId): void
    {
        if (!Uuid::isValid($postId)) {
            return;
        }
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM saved_post WHERE user_id = :user AND post_id = :post',
            ['user' => $user->getId()->toRfc4122(), 'post' => $postId],
        );
    }

    /**
     * Uma consulta para a página inteira. A chave é o UUID da publicação.
     *
     * @param list<Post> $posts
     *
     * @return array<string, true>
     */
    public function flagsFor(User $viewer, array $posts): array
    {
        if ([] === $posts) {
            return [];
        }
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT post_id FROM saved_post WHERE user_id = :user AND post_id IN (:ids)',
            [
                'user' => $viewer->getId()->toRfc4122(),
                'ids' => array_map(static fn (Post $post): string => $post->getId()->toRfc4122(), $posts),
            ],
            ['ids' => ArrayParameterType::STRING],
        )->fetchFirstColumn();
        $flags = [];
        foreach ($rows as $id) {
            $flags[strtolower((string) $id)] = true;
        }

        return $flags;
    }

    /**
     * Página das referências que a pessoa ainda pode ler, da mais recente para a mais antiga.
     * O limite vale antes dos joins de enquete, para uma enquete não encolher a página.
     *
     * @return list<SavedPost>
     */
    public function findVisiblePage(User $viewer, ?Uuid $before, int $limit): array
    {
        $memberOf = sprintf('SELECT IDENTITY(sm.comuna) FROM %s sm WHERE sm.user = :savedViewer', ComunaMembership::class);
        $qb = $this->createQueryBuilder('s')
            ->select('s.id')
            ->join('s.post', 'p')
            ->join('p.author', 'a')
            ->leftJoin('p.comuna', 'c')
            ->where('s.user = :saver')
            ->setParameter('saver', $viewer)
            ->andWhere(sprintf('c.id IS NULL OR c.visibility = :savedPublic OR c.id IN (%s)', $memberOf))
            ->setParameter('savedPublic', ComunaVisibility::Public)
            ->setParameter('savedViewer', $viewer)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($limit + 1);
        $this->posts->applyVisibility($qb, 'p', 'a', 'c', $viewer);
        if (null !== $before) {
            $qb->andWhere('s.id < :before')->setParameter('before', $before, 'uuid');
        }
        /** @var list<Uuid|string> $raw */
        $raw = $qb->getQuery()->getSingleColumnResult();
        if ([] === $raw) {
            return [];
        }
        $ids = array_map(static fn (Uuid|string $id): Uuid => $id instanceof Uuid ? $id : Uuid::fromString($id), $raw);

        /** @var list<SavedPost> $rows */
        $rows = $this->createQueryBuilder('s')
            ->addSelect('p', 'a', 'c', 'ld', 'lf', 'lc', 'lu', 'pmedia', 'lou', 'loc', 'poll', 'pollOption')
            ->join('s.post', 'p')
            ->join('p.author', 'a')
            ->leftJoin('p.comuna', 'c')
            ->leftJoin('p.libraryDocument', 'ld')->leftJoin('ld.file', 'lf')->leftJoin('ld.collection', 'lc')->leftJoin('lf.uploadedBy', 'lu')
            ->leftJoin('ld.user', 'lou')->leftJoin('ld.comuna', 'loc')
            ->leftJoin('p.media', 'pmedia')
            ->leftJoin('p.poll', 'poll')->leftJoin('poll.options', 'pollOption')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->addOrderBy('pollOption.position', 'ASC')
            ->getQuery()
            ->getResult();
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->id->toRfc4122()] = $row;
        }
        $ordered = [];
        foreach ($ids as $id) {
            $key = $id->toRfc4122();
            if (isset($byId[$key])) {
                $ordered[] = $byId[$key];
            }
        }

        return $ordered;
    }
}
