<?php

declare(strict_types=1);

namespace App\Hashtag;

use App\Entity\Post;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Called inside the post transaction, after the post row has been flushed. */
final readonly class HashtagBinder
{
    public function __construct(private Connection $db)
    {
    }

    /** @param array<string, string> $names */
    public function attach(Post $post, array $names): void
    {
        foreach ($names as $normalized => $display) {
            // The unique constraint arbitrates concurrent first uses. ON CONFLICT waits for
            // the competing transaction, then the SELECT sees its committed spelling.
            $this->db->executeStatement('INSERT INTO hashtag (id, normalized_name, display_name) VALUES (:id, :name, :display) ON CONFLICT (normalized_name) DO NOTHING', [
                'id' => Uuid::v7()->toRfc4122(), 'name' => $normalized, 'display' => $display,
            ]);
            $id = $this->db->fetchOne('SELECT id FROM hashtag WHERE normalized_name = :name', ['name' => $normalized]);
            $this->db->executeStatement('INSERT INTO post_hashtag (post_id, hashtag_id) VALUES (:post, :hashtag) ON CONFLICT DO NOTHING', [
                'post' => $post->getId()->toRfc4122(), 'hashtag' => $id,
            ]);
        }
    }

    public function deleteForPost(Post $post): void
    {
        $this->db->executeStatement('DELETE FROM post_hashtag WHERE post_id = :post', ['post' => $post->getId()->toRfc4122()]);
    }
}
