<?php

declare(strict_types=1);

namespace App\Gamification;

/** Conquistas da V1. Cada uma desbloqueia uma vez por conta. */
enum AchievementCode: string
{
    case PrimeiroPasso = 'primeiro_passo';
    case ParaDepois = 'para_depois';
    case NaConversa = 'na_conversa';
    case EmComunidade = 'em_comunidade';
    case Organizando = 'organizando';
    case Constancia = 'constancia';
    case UmaSemana = 'uma_semana';

    public function title(): string
    {
        return match ($this) {
            self::PrimeiroPasso => 'Primeiro passo',
            self::ParaDepois => 'Para depois',
            self::NaConversa => 'Na conversa',
            self::EmComunidade => 'Em comunidade',
            self::Organizando => 'Organizando',
            self::Constancia => 'Constância',
            self::UmaSemana => 'Uma semana',
        };
    }

    /** Como ganhar, na voz de quem ainda não desbloqueou. */
    public function criterion(): string
    {
        return match ($this) {
            self::PrimeiroPasso => 'Participe de uma enquete.',
            self::ParaDepois => 'Salve uma publicação.',
            self::NaConversa => 'Participe de uma discussão.',
            self::EmComunidade => 'Entre em uma comuna.',
            self::Organizando => 'Confirme interesse em um evento.',
            self::Constancia => 'Mantenha três dias seguidos de atividade.',
            self::UmaSemana => 'Mantenha sete dias seguidos de atividade.',
        };
    }

    public function detail(): string
    {
        return match ($this) {
            self::PrimeiroPasso => 'Participou da primeira enquete.',
            self::ParaDepois => 'Salvou a primeira publicação.',
            self::NaConversa => 'Entrou na primeira discussão.',
            self::EmComunidade => 'Entrou na primeira comuna.',
            self::Organizando => 'Demonstrou interesse no primeiro evento.',
            self::Constancia => 'Manteve três dias seguidos de atividade.',
            self::UmaSemana => 'Manteve sete dias seguidos de atividade.',
        };
    }
}
