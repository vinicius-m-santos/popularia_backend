<?php

declare(strict_types=1);

namespace App\Api\Post;

final readonly class PollOptionView
{
    public function __construct(
        public string $id,
        public string $label,
        public int $voteCount,
        public int $percent,
        public bool $selected,
    ) {
    }
}
