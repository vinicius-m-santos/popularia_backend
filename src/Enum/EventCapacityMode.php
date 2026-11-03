<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Como o chamado mede a presença. Sem modo, é entrada livre.
 * Spots é um teto de vagas; Goal é o mínimo de confirmações para o encontro acontecer.
 */
enum EventCapacityMode: string
{
    case Spots = 'spots';
    case Goal = 'goal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
