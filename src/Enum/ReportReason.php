<?php

declare(strict_types=1);

namespace App\Enum;

/** Motivo fechado de denúncia. Discordância política não é categoria. */
enum ReportReason: string
{
    case Harassment = 'harassment';
    case Hate = 'hate';
    case Threat = 'threat';
    case Spam = 'spam';
    case Fraud = 'fraud';
    case Sexual = 'sexual';
    case Doxxing = 'doxxing';
    case Illegal = 'illegal';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Harassment => 'Assédio ou intimidação',
            self::Hate => 'Discurso de ódio ou preconceito',
            self::Threat => 'Ameaça ou violência',
            self::Spam => 'Spam',
            self::Fraud => 'Golpe ou fraude',
            self::Sexual => 'Conteúdo sexual impróprio',
            self::Doxxing => 'Exposição de dados pessoais',
            self::Illegal => 'Conteúdo ilegal',
            self::Other => 'Outro',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
