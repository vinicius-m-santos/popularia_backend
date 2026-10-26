<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\AuthSession;

/** Resultado de login ou renovação. Os tokens em texto só existem aqui, na resposta. */
final readonly class TokenPair
{
    public function __construct(
        public AuthSession $session,
        #[\SensitiveParameter] public string $accessToken,
        public int $accessTokenExpiresIn,
        #[\SensitiveParameter] public string $refreshToken,
        public int $refreshTokenExpiresIn,
    ) {
    }
}
