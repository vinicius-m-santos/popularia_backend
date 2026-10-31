<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * Comentário em destaque de cada publicação: o de topo com mais apoios de pessoas ativas.
     * Ignora respostas (o contexto do pai se perderia), comentários removidos, autores inativos
     * e autores com bloqueio em qualquer sentido com quem está vendo. O empate fica com o mais antigo.
     *
     * @param list<Post>   $posts
     * @param list<string> $blockedUserIds IDs RFC 4122 de quem bloqueou ou foi bloqueado pela pessoa
     *
     * @return array<string, array{comment: Comment, supportCount: int}> indexado pelo ID da publicação
     */
    public function topForPosts(array $posts, array $blockedUserIds = []): array
    {
        if ([] === $posts) {
            return [];
        }

        $postIds = array_map(static fn (Post $post): string => $post->getId()->toRfc4122(), $posts);
        $sql = <<<'SQL'
            SELECT DISTINCT ON (c.post_id)
                c.id,
                c.post_id,
                COALESCE(s.total, 0) AS support_total
            FROM comment c
            JOIN app_user a ON a.id = c.author_id AND a.status = 'active'
            LEFT JOIN (
                SELECT cs.comment_id, COUNT(*) AS total
                FROM comment_support cs
                JOIN app_user su ON su.id = cs.user_id AND su.status = 'active'
                JOIN comment cc ON cc.id = cs.comment_id
                WHERE cc.post_id IN (:supportPostIds)
                GROUP BY cs.comment_id
            ) s ON s.comment_id = c.id
            WHERE c.post_id IN (:postIds)
              AND c.parent_id IS NULL
              AND c.status = 'published'
            SQL;
        $params = ['postIds' => $postIds, 'supportPostIds' => $postIds];
        $types = ['postIds' => ArrayParameterType::STRING, 'supportPostIds' => ArrayParameterType::STRING];
        if ([] !== $blockedUserIds) {
            $sql .= "\n  AND c.author_id NOT IN (:blocked)";
            $params['blocked'] = $blockedUserIds;
            $types['blocked'] = ArrayParameterType::STRING;
        }
        $sql .= "\nORDER BY c.post_id, support_total DESC, c.created_at ASC, c.id ASC";

        /** @var list<array{id: string, post_id: string, support_total: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types);
        if ([] === $rows) {
            return [];
        }

        $commentIds = array_map(static fn (array $row): Uuid => Uuid::fromString($row['id']), $rows);
        /** @var list<Comment> $comments */
        $comments = $this->createQueryBuilder('c')
            ->addSelect('a')
            ->join('c.author', 'a')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $commentIds)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($comments as $comment) {
            $byId[$comment->getId()->toRfc4122()] = $comment;
        }

        $result = [];
        foreach ($rows as $row) {
            $comment = $byId[$row['id']] ?? null;
            if (null === $comment) {
                continue;
            }
            $result[$row['post_id']] = ['comment' => $comment, 'supportCount' => (int) $row['support_total']];
        }

        return $result;
    }
}
