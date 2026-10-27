<?php

declare(strict_types=1);

namespace App\Api\Auth;

use App\Auth\TokenPair;

/**
 * Resposta do social login: mesmos tokens da sessão Popularia normal, mais o
 * flag `onboardingRequired` para novas contas que ainda não escolheram handle e nome.
 */
final readonly class SocialTokens
{
    public function __construct(
        public string $tokenType,
        public string $accessToken,
        public int $accessTokenExpiresIn,
        public string $refreshToken,
        public int $refreshTokenExpiresIn,
        public string $sessionId,
        /** true quando é uma conta nova que ainda não completou o onboarding (handle, nome). */
        public bool $onboardingRequired,
    ) {
    }

    public static function fromPair(TokenPair $pair, bool $onboardingRequired): self
    {
        return new self(
            'Bearer',
            $pair->accessToken,
            $pair->accessTokenExpiresIn,
            $pair->refreshToken,
            $pair->refreshTokenExpiresIn,
            $pair->session->getId()->toRfc4122(),
            $onboardingRequired,
        );
    }
}
