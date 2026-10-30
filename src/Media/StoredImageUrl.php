<?php

declare(strict_types=1);

namespace App\Media;

use App\Entity\Comuna;
use App\Entity\User;

/** URL estável do objeto atual. O UUID da key muda a cada troca, então o cache não reaproveita a imagem anterior. */
final class StoredImageUrl
{
    public static function avatar(User $user): ?string
    {
        return self::path('avatars', $user->getAvatarKey(), $user->getId()->toRfc4122());
    }

    public static function cover(Comuna $comuna): ?string
    {
        return self::path('comuna-covers', $comuna->getCoverKey(), $comuna->getId()->toRfc4122());
    }

    public static function mark(Comuna $comuna): ?string
    {
        return self::path('comuna-marks', $comuna->getMarkKey(), $comuna->getId()->toRfc4122());
    }

    private static function path(string $prefix, ?string $key, string $ownerId): ?string
    {
        if (null === $key || !preg_match('#^'.preg_quote($prefix, '#').'/([0-9a-fA-F-]{36})\.(jpg|png|webp)$#', $key, $match)) {
            return null;
        }

        return '/api/v1/media/'.$prefix.'/'.$ownerId.'/'.$match[1];
    }

    public static function matches(string $prefix, ?string $key, ?string $fileId = null): bool
    {
        if (null === $key) {
            return false;
        }
        $file = null === $fileId ? '[0-9a-fA-F-]{36}' : preg_quote($fileId, '#');

        return 1 === preg_match('#^'.preg_quote($prefix, '#').'/'.$file.'\.(jpg|png|webp)$#', $key);
    }
}
