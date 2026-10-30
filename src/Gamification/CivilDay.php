<?php

declare(strict_types=1);

namespace App\Gamification;

/**
 * Dia civil da gamificação.
 *
 * O produto não guarda fuso na conta: eventos usam o fuso de quem organiza e o
 * servidor fica em UTC. A sequência e as missões usam um dia só, America/Sao_Paulo.
 * O Brasil não tem horário de verão desde 2019, então a meia-noite desse fuso é
 * estável. Quem está em outro fuso brasileiro vê a virada no máximo duas horas
 * fora do relógio local — ainda assim de noite, e não quando o UTC vira.
 *
 * O instante é sempre o relógio do servidor. Não existe data enviada pelo cliente.
 */
final class CivilDay
{
    public const string ZONE = 'America/Sao_Paulo';

    public static function date(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d');
    }

    public static function previous(string $date): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone(self::ZONE)))->modify('-1 day')->format('Y-m-d');
    }

    /** Dias desde 2026-01-01 nesse fuso. Serve só para girar as missões. */
    public static function index(string $date): int
    {
        $zone = new \DateTimeZone(self::ZONE);
        $day = new \DateTimeImmutable($date, $zone);
        $epoch = new \DateTimeImmutable('2026-01-01', $zone);

        return intdiv($day->getTimestamp() - $epoch->getTimestamp(), 86400);
    }
}
