<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'post_hashtag')]
#[ORM\Index(name: 'idx_post_hashtag_hashtag_post', columns: ['hashtag_id', 'post_id'])]
class PostHashtag
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Post::class)]
    #[ORM\JoinColumn(name: 'post_id', nullable: false, onDelete: 'CASCADE')]
    public Post $post;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Hashtag::class)]
    #[ORM\JoinColumn(name: 'hashtag_id', nullable: false, onDelete: 'CASCADE')]
    public Hashtag $hashtag;
}
