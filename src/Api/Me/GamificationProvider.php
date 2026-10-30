<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Gamification\AchievementCode;
use App\Gamification\GamificationCatalog;
use App\Gamification\GamificationService;
use App\Security\CurrentUser;

/** @implements ProviderInterface<GamificationView> */
final readonly class GamificationProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private GamificationService $gamification,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GamificationView
    {
        $state = $this->gamification->view($this->currentUser->get());
        $missions = [];
        foreach ($state->missions as $mission) {
            $missions[] = new GamificationMissionView($mission['action']->value, $mission['action']->label(), $mission['completed']);
        }
        $achievements = [];
        foreach (AchievementCode::cases() as $code) {
            $at = $state->unlockedAt[$code->value] ?? null;
            $achievements[] = new GamificationAchievementView($code->value, $code->title(), $code->detail(), null !== $at, $at);
        }

        return new GamificationView(
            $state->userId,
            $state->level->level,
            $state->level->xp,
            $state->level->currentLevelXp,
            $state->level->nextLevelXp,
            $state->streak,
            GamificationCatalog::MISSION,
            GamificationCatalog::DAY,
            $missions,
            $achievements,
        );
    }
}
