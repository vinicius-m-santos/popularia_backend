<?php

declare(strict_types=1);

namespace App\Api\Discovery;

/** Identidade pública e relação com quem busca; nunca inclui interesses. */
final readonly class DiscoveryPerson
{
    public function __construct(
        public string $id,
        public string $handle,
        public string $displayName,
        public bool $following,
        public ?string $avatarUrl = null,
        public bool $requested = false,
        public bool $isPrivate = false,
    ) {
    }
}
