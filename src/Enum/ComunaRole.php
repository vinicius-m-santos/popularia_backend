<?php

declare(strict_types=1);

namespace App\Enum;

/** Papel dentro de uma comuna. Independente do papel na plataforma (PlatformRole). */
enum ComunaRole: string
{
    case Member = 'member';
    case Moderator = 'moderator';
    case Founder = 'founder';

    public function canModerate(): bool
    {
        return self::Member !== $this;
    }
}
