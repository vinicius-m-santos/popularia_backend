<?php

declare(strict_types=1);

namespace App\Api\Profile;

use App\Entity\User;
use App\Media\StoredImageUrl;

/** Pessoa numa lista de seguidores ou seguindo, com a relação de quem está olhando. */
final readonly class FollowListPerson
{
    public function __construct(
        public string $id,
        public string $handle,
        public string $displayName,
        public bool $isPrivate,
        public bool $following,
        public bool $requested,
        public ?string $avatarUrl = null,
    ) {
    }

    public static function fromUser(User $user, bool $following, bool $requested): self
    {
        return new self(
            $user->getId()->toRfc4122(),
            $user->getHandle(),
            $user->getDisplayName(),
            $user->isPrivate(),
            $following,
            $requested,
            StoredImageUrl::avatar($user),
        );
    }
}
