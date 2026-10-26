<?php

declare(strict_types=1);

namespace App\Api\Profile;

use App\Entity\User;

/** Autoria de publicações e comentários: só dados públicos do perfil. */
final readonly class AuthorView
{
    public function __construct(
        public string $id,
        public string $handle,
        public string $displayName,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self($user->getId()->toRfc4122(), $user->getHandle(), $user->getDisplayName());
    }
}
