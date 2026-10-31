<?php

declare(strict_types=1);

namespace App\Api\Post;

use App\Api\Profile\AuthorView;

/** Comentário em destaque no cartão: o mais apoiado entre os comentários de topo. */
final readonly class TopCommentView
{
    public function __construct(
        public string $id,
        public string $body,
        public int $supportCount,
        public AuthorView $author,
    ) {
    }
}
