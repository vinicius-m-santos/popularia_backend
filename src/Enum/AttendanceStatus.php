<?php

declare(strict_types=1);

namespace App\Enum;

/** Uma pessoa, uma resposta. Trocar substitui a anterior. */
enum AttendanceStatus: string
{
    case Going = 'going';
    case Maybe = 'maybe';
    case NotGoing = 'not_going';

    public function wantsReminder(): bool
    {
        return self::NotGoing !== $this;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
