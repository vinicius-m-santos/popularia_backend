<?php

declare(strict_types=1);

namespace App\Api\Post;

final readonly class PollView
{
    /**
     * @param list<PollOptionView> $options
     */
    public function __construct(
        public string $id,
        public string $question,
        public int $totalVotes,
        public ?string $votedOptionId,
        public array $options,
    ) {
    }
}
