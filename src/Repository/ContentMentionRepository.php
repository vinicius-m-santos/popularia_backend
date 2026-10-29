<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\ContentMention;
use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContentMention> */
class ContentMentionRepository extends ServiceEntityRepository
{
    /**
     * Consultas de leitura nesta requisição. Os testes conferem que uma página
     * não busca menção por menção.
     */
    public static int $reads = 0;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentMention::class);
    }

    public static function resetReads(): void
    {
        self::$reads = 0;
    }

    public static function takeReads(): int
    {
        $reads = self::$reads;
        self::$reads = 0;

        return $reads;
    }

    /**
     * @param list<Post> $posts
     *
     * @return array<string, list<ContentMention>>
     */
    public function groupedByPost(array $posts): array
    {
        $grouped = [];
        foreach ($posts as $post) {
            $grouped[$post->getId()->toRfc4122()] = [];
        }
        if ([] === $posts) {
            return $grouped;
        }
        ++self::$reads;
        /** @var list<ContentMention> $rows */
        $rows = $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.mentionedUser', 'u')
            ->where('m.post IN (:posts)')
            ->setParameter('posts', $posts)
            ->orderBy('m.startOffset', 'ASC')
            ->getQuery()
            ->getResult();
        foreach ($rows as $row) {
            $post = $row->getPost();
            if (null !== $post) {
                $grouped[$post->getId()->toRfc4122()][] = $row;
            }
        }

        return $grouped;
    }

    /**
     * @param list<Comment> $comments
     *
     * @return array<string, list<ContentMention>>
     */
    public function groupedByComment(array $comments): array
    {
        $grouped = [];
        foreach ($comments as $comment) {
            $grouped[$comment->getId()->toRfc4122()] = [];
        }
        if ([] === $comments) {
            return $grouped;
        }
        ++self::$reads;
        /** @var list<ContentMention> $rows */
        $rows = $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.mentionedUser', 'u')
            ->where('m.comment IN (:comments)')
            ->setParameter('comments', $comments)
            ->orderBy('m.startOffset', 'ASC')
            ->getQuery()
            ->getResult();
        foreach ($rows as $row) {
            $comment = $row->getComment();
            if (null !== $comment) {
                $grouped[$comment->getId()->toRfc4122()][] = $row;
            }
        }

        return $grouped;
    }

    public function deleteForPost(Post $post): void
    {
        $this->createQueryBuilder('m')
            ->delete()
            ->where('m.post = :post')
            ->setParameter('post', $post)
            ->getQuery()
            ->execute();
    }
}
