<?php

declare(strict_types=1);

namespace App\Api\Auth;

use App\Auth\TokenPair;

/**
 * Tokens de uma sessão. O app guarda o refresh token no armazenamento seguro do sistema
 * (Keychain/Keystore via expo-secure-store) e o access token só na memória.
 */
final readonly class Tokens
{
    public function __construct(
        public string $tokenType,
        public string $accessToken,
        /** Segundos até o access token vencer. */
        public int $accessTokenExpiresIn,
        public string $refreshToken,
        /** Segundos até o refresh token vencer, se não for usado antes. */
        public int $refreshTokenExpiresIn,
        public string $sessionId,
    ) {
    }

    public static function fromPair(TokenPair $pair): self
    {
        return new self(
            'Bearer',
            $pair->accessToken,
            $pair->accessTokenExpiresIn,
            $pair->refreshToken,
            $pair->refreshTokenExpiresIn,
            $pair->session->getId()->toRfc4122(),
        );
    }
}
