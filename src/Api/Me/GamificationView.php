<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;

/**
 * Progresso da própria conta. Não inclui o livro de XP, publicações salvas,
 * opção de enquete, presença em evento nem horário das ações.
 */
#[ApiResource(
    shortName: 'Gamification',
    operations: [
        new Get(
            uriTemplate: '/me/gamification',
            security: "is_granted('ROLE_USER')",
            provider: GamificationProvider::class,
            openapi: new Operation(
                tags: ['Conta'],
                summary: 'Nível, sequência, missões de hoje e conquistas da conta autenticada.',
                description: 'Privado. O nível não altera feed, alcance nem permissão. Não há ranking nem histórico de XP.',
            ),
        ),
    ],
)]
final readonly class GamificationView
{
    /**
     * @param list<GamificationMissionView>     $missions
     * @param list<GamificationAchievementView> $achievements
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public int $level,
        public int $xp,
        public int $currentLevelXp,
        public int $nextLevelXp,
        public int $streak,
        public int $missionBonusXp,
        public int $dayBonusXp,
        public array $missions,
        public array $achievements,
    ) {
    }
}
