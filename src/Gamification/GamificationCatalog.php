<?php

declare(strict_types=1);

namespace App\Gamification;

/**
 * Recompensas da V1, num lugar só.
 *
 * Participar de enquete 5, salvar 3, discussão 5, entrar numa comuna 10,
 * interesse em evento 8. Missão concluída soma 4. Fechar as três soma mais 6.
 * Discussão: no máximo 3 publicações diferentes por dia civil.
 *
 * Publicar e seguir não estão aqui. XP concedido não é retirado.
 */
final class GamificationCatalog
{
    public const int POLL = 5;
    public const int SAVE = 3;
    public const int DISCUSSION = 5;
    public const int COMUNA = 10;
    public const int EVENT = 8;
    public const int MISSION = 4;
    public const int DAY = 6;
    public const int DISCUSSION_DAILY_CAP = 3;

    public const string MISSION_ACTION = 'mission_completed';
    public const string DAY_ACTION = 'missions_cleared';

    public static function xp(GamificationAction $action): int
    {
        return match ($action) {
            GamificationAction::PollParticipated => self::POLL,
            GamificationAction::PostSaved => self::SAVE,
            GamificationAction::DiscussionParticipated => self::DISCUSSION,
            GamificationAction::ComunaJoined => self::COMUNA,
            GamificationAction::EventRsvpPositive => self::EVENT,
        };
    }
}
