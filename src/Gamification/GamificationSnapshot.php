<?php

declare(strict_types=1);

namespace App\Gamification;

/** Leitura já consolidada. Não inclui o livro de XP nem a origem de cada ação. */
final readonly class GamificationSnapshot
{
    /**
     * @param list<array{action: GamificationAction, completed: bool}> $missions
     * @param array<string, \DateTimeImmutable>                        $unlockedAt
     */
    public function __construct(
        public string $userId,
        public LevelProgress $level,
        public int $streak,
        public array $missions,
        public array $unlockedAt,
    ) {
    }
}
