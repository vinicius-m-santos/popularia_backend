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
