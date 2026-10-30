<?php

declare(strict_types=1);

namespace App\Enum;

/** Onde a atividade acontece. Híbrido é um tipo só, não duas entidades. */
enum EventFormat: string
{
    case InPerson = 'in_person';
    case Online = 'online';
    case Hybrid = 'hybrid';

    public function needsPlace(): bool
    {
        return self::Online !== $this;
    }

    public function needsUrl(): bool
    {
        return self::InPerson !== $this;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
