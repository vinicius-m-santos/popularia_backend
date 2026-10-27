<?php

declare(strict_types=1);

namespace App\Api\Me;

use App\Entity\UserAuthIdentity;

final readonly class AuthIdentitiesView
{
    /** @param list<AuthIdentityItemView> $identities */
    public function __construct(
        public array $identities,
    ) {
    }

    /** @param list<UserAuthIdentity> $entities */
    public static function fromEntities(array $entities): self
    {
        return new self(array_map(
            static fn (UserAuthIdentity $id) => new AuthIdentityItemView(
                $id->getProvider(),
                $id->getCreatedAt()->format(\DateTimeInterface::RFC3339),
            ),
            $entities,
        ));
    }
}

final readonly class AuthIdentityItemView
{
    public function __construct(
        public string $provider,
        public string $linkedAt,
    ) {
    }
}
