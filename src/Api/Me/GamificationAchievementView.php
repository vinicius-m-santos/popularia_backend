<?php

declare(strict_types=1);

namespace App\Api\Me;

final readonly class GamificationAchievementView
{
    public function __construct(
        public string $code,
        public string $title,
        public string $detail,
        public bool $unlocked,
        public ?\DateTimeImmutable $unlockedAt,
    ) {
    }
}
