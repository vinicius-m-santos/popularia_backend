<?php

declare(strict_types=1);

namespace App\Enum;

/** Cancelar não apaga. Passado não é status: deriva do horário. */
enum EventStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
}
