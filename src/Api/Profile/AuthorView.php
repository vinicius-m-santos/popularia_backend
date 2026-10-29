<?php

declare(strict_types=1);

namespace App\Api\Profile;

use App\Entity\User;
use App\Media\StoredImageUrl;

/** Autoria de publicações e comentários: só dados públicos do perfil. */
final readonly class AuthorView
{
    public function __construct(
        public string $id,
        public string $handle,
        public string $displayName,
        /** URL estável da foto atual. Muda só quando a foto é trocada; null são as iniciais. */
        public ?string $avatarUrl = null,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self($user->getId()->toRfc4122(), $user->getHandle(), $user->getDisplayName(), StoredImageUrl::avatar($user));
    }
}
