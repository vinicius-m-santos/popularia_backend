<?php

declare(strict_types=1);

namespace App\Tests\Gamification;

use App\Gamification\CivilDay;
use App\Gamification\GamificationAction;
use App\Gamification\GamificationMissions;
use PHPUnit\Framework\TestCase;

final class CivilDayTest extends TestCase
{
    public function testSaoPauloEveningStaysOnTheSameCivilDayAfterUtcMidnight(): void
    {
        $evening = new \DateTimeImmutable('2026-06-16T01:30:00+00:00');
        $stillEvening = new \DateTimeImmutable('2026-06-16T02:30:00+00:00');
        $nextMorning = new \DateTimeImmutable('2026-06-16T03:00:00+00:00');

        self::assertSame('2026-06-15', CivilDay::date($evening));
        self::assertSame('2026-06-15', CivilDay::date($stillEvening));
        self::assertSame('2026-06-16', CivilDay::date($nextMorning));
        self::assertSame('2026-06-15', CivilDay::previous('2026-06-16'));
    }
}

final class MissionRotationTest extends TestCase
{
    public function testRotationIsStableForADayAndChangesOnTheNextWhenThereIsChoice(): void
    {
        $all = GamificationAction::cases();
        $monday = GamificationMissions::rotate($all, '2026-06-15');
        self::assertSame($monday, GamificationMissions::rotate($all, '2026-06-15'));
        self::assertCount(3, $monday);
        self::assertNotSame($monday, GamificationMissions::rotate($all, '2026-06-16'));
    }

    public function testUnavailableActionIsNeverSelected(): void
    {
        $withoutPoll = array_values(array_filter(
            GamificationAction::cases(),
            static fn (GamificationAction $action): bool => GamificationAction::PollParticipated !== $action,
        ));
        $picked = GamificationMissions::rotate($withoutPoll, '2026-06-15');

        self::assertCount(3, $picked);
        self::assertNotContains(GamificationAction::PollParticipated, $picked);
    }

    public function testShortListIsKeptInsteadOfInventingAMission(): void
    {
        $only = [GamificationAction::PostSaved, GamificationAction::ComunaJoined];

        self::assertSame($only, GamificationMissions::rotate($only, '2026-06-15'));
        self::assertSame($only, GamificationMissions::rotate($only, '2026-06-16'));
    }
}
