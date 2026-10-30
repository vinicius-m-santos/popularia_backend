<?php

declare(strict_types=1);

namespace App\Tests\Gamification;

use App\Gamification\LevelProgress;
use PHPUnit\Framework\TestCase;

final class LevelProgressTest extends TestCase
{
    public function testThresholdsGrowAndAreNotLinear(): void
    {
        self::assertSame(0, LevelProgress::threshold(1));
        self::assertSame(100, LevelProgress::threshold(2));
        self::assertSame(250, LevelProgress::threshold(3));
        self::assertSame(450, LevelProgress::threshold(4));
        self::assertSame(700, LevelProgress::threshold(5));
        self::assertSame(1000, LevelProgress::threshold(6));
        self::assertGreaterThan(
            LevelProgress::threshold(5) - LevelProgress::threshold(4),
            LevelProgress::threshold(6) - LevelProgress::threshold(5),
        );
    }

    public function testBoundariesAndProgress(): void
    {
        $cases = [
            [0, 1, 0, 100],
            [99, 1, 0, 100],
            [100, 2, 100, 250],
            [249, 2, 100, 250],
            [250, 3, 250, 450],
            [449, 3, 250, 450],
            [450, 4, 450, 700],
            [699, 4, 450, 700],
            [700, 5, 700, 1000],
        ];
        foreach ($cases as [$xp, $level, $current, $next]) {
            $progress = LevelProgress::fromXp($xp);
            self::assertSame($level, $progress->level, 'nível de '.$xp);
            self::assertSame($xp, $progress->xp);
            self::assertSame($current, $progress->currentLevelXp);
            self::assertSame($next, $progress->nextLevelXp);
        }
    }

    public function testHigherLevelsStayConsistentWithTheFormula(): void
    {
        for ($level = 1; $level <= 30; ++$level) {
            $start = LevelProgress::threshold($level);
            $next = LevelProgress::threshold($level + 1);
            self::assertGreaterThan($start, $next);
            $atStart = LevelProgress::fromXp($start);
            self::assertSame($level, $atStart->level);
            self::assertSame($start, $atStart->currentLevelXp);
            self::assertSame($next, $atStart->nextLevelXp);
            if ($next - $start > 1) {
                $inside = LevelProgress::fromXp($next - 1);
                self::assertSame($level, $inside->level);
                self::assertSame($next, $inside->nextLevelXp);
            }
        }
    }
}
