<?php

declare(strict_types=1);

namespace App\Gamification;

/**
 * Progressão crescente. O nível L >= 2 começa em 25*L*(L+1)-50 XP.
 * Os degraus são 100, 150, 200, 250… — não uma divisão linear eterna.
 * Nível não altera feed, alcance, moderação nem permissão.
 */
final readonly class LevelProgress
{
    public function __construct(
        public int $level,
        public int $xp,
        public int $currentLevelXp,
        public int $nextLevelXp,
    ) {
    }

    public static function threshold(int $level): int
    {
        if ($level <= 1) {
            return 0;
        }

        return 25 * $level * ($level + 1) - 50;
    }

    public static function fromXp(int $xp): self
    {
        $xp = max(0, $xp);
        $level = 1;
        if ($xp > 0) {
            $c = intdiv($xp + 50, 25);
            $level = (int) floor((-1 + sqrt(1 + 4 * $c)) / 2);
            if ($level < 1) {
                $level = 1;
            }
        }
        while (self::threshold($level + 1) <= $xp) {
            ++$level;
        }
        while ($level > 1 && self::threshold($level) > $xp) {
            --$level;
        }

        return new self($level, $xp, self::threshold($level), self::threshold($level + 1));
    }
}
