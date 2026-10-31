<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PostEcho;
use App\Entity\Post;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PostEcho> */
final class PostEchoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostEcho::class);
    }

    /** @param list<Post> $posts
     * @return array<string, array{count: int, mine: bool}>
     */
    public function stats(array $posts, ?User $viewer): array
    {
        if ([] === $posts) {
            return [];
        }
        $viewerId = $viewer?->getId()->toRfc4122();
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT s.post_id, COUNT(*) AS total, BOOL_OR(s.user_id = :viewer) AS mine FROM post_echo s JOIN app_user u ON u.id = s.user_id WHERE s.post_id IN (:ids) AND u.status = 'active' GROUP BY s.post_id",
            ['viewer' => $viewerId ?? '00000000-0000-0000-0000-000000000000', 'ids' => array_map(static fn (Post $post) => $post->getId()->toRfc4122(), $posts)],
            ['ids' => ArrayParameterType::STRING],
        );
        $result = [];
        foreach ($rows as $row) {
            $mine = $row['mine'];
            $result[(string) $row['post_id']] = ['count' => (int) $row['total'], 'mine' => true === $mine || 't' === $mine || '1' === $mine || 1 === $mine];
        }

        return $result;
    }
}
