<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\CommentSupport;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CommentSupport> */
final class CommentSupportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommentSupport::class);
    }

    /** @param list<Comment> $comments
     * @return array<string, array{count: int, mine: bool}>
     */
    public function stats(array $comments, ?User $viewer): array
    {
        if ([] === $comments) {
            return [];
        }
        $viewerId = $viewer?->getId()->toRfc4122();
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT s.comment_id, COUNT(*) AS total, BOOL_OR(s.user_id = :viewer) AS mine FROM comment_support s JOIN app_user u ON u.id = s.user_id WHERE s.comment_id IN (:ids) AND u.status = 'active' GROUP BY s.comment_id",
            ['viewer' => $viewerId ?? '00000000-0000-0000-0000-000000000000', 'ids' => array_map(static fn (Comment $comment) => $comment->getId()->toRfc4122(), $comments)],
            ['ids' => ArrayParameterType::STRING],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['comment_id']] = ['count' => (int) $row['total'], 'mine' => true === $row['mine'] || 't' === $row['mine'] || '1' === $row['mine'] || 1 === $row['mine']];
        }

        return $result;
    }
}
