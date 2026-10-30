<?php

declare(strict_types=1);

namespace App\Gamification;

/**
 * Ações de participação que podem gerar XP, sequência, missão e conquista.
 * O valor não diz qual opção, qual publicação salva nem o RSVP escolhido.
 */
enum GamificationAction: string
{
    case PollParticipated = 'poll_participated';
    case PostSaved = 'post_saved';
    case DiscussionParticipated = 'discussion_participated';
    case ComunaJoined = 'comuna_joined';
    case EventRsvpPositive = 'event_rsvp_positive';

    public function sourceType(): string
    {
        return match ($this) {
            self::PollParticipated => 'poll',
            self::PostSaved, self::DiscussionParticipated => 'post',
            self::ComunaJoined => 'comuna',
            self::EventRsvpPositive => 'event',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PollParticipated => 'Participe de uma enquete',
            self::PostSaved => 'Salve uma publicação',
            self::DiscussionParticipated => 'Participe de uma discussão',
            self::ComunaJoined => 'Entre em uma comuna',
            self::EventRsvpPositive => 'Confirme interesse em um evento',
        };
    }

    public function achievement(): AchievementCode
    {
        return match ($this) {
            self::PollParticipated => AchievementCode::PrimeiroPasso,
            self::PostSaved => AchievementCode::ParaDepois,
            self::DiscussionParticipated => AchievementCode::NaConversa,
            self::ComunaJoined => AchievementCode::EmComunidade,
            self::EventRsvpPositive => AchievementCode::Organizando,
        };
    }
}
