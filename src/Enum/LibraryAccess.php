<?php

declare(strict_types=1);

namespace App\Enum;

enum LibraryAccess: string
{
    case Private = 'private';
    case Followers = 'followers';
    case Visitors = 'visitors';
    case Members = 'members';
    case Moderators = 'moderators';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
