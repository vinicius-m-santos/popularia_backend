<?php

declare(strict_types=1);

namespace App\Api\Me;

final readonly class GamificationMissionView
{
    public function __construct(
        public string $action,
        public string $label,
        public bool $completed,
    ) {
    }
}
