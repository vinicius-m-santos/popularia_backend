<?php

declare(strict_types=1);

namespace App\Api\Auth;

use App\Entity\PendingSocialSignup;

/**
 * Resultado do login social: sessão existente ou cadastro pendente que ainda
 * precisa ser concluído no app. Nunca cria conta ativa sem aceite.
 */
final readonly class SocialAuthResult
{
    public function __construct(
        public string $status,
        public ?string $pendingSignupToken = null,
        public ?string $provider = null,
        public ?string $email = null,
        public ?string $displayName = null,
        public ?string $expiresAt = null,
        public ?string $tokenType = null,
        public ?string $accessToken = null,
        public ?int $accessTokenExpiresIn = null,
        public ?string $refreshToken = null,
        public ?int $refreshTokenExpiresIn = null,
        public ?string $sessionId = null,
        public ?bool $onboardingRequired = null,
    ) {
    }

    public static function authenticated(SocialTokens $tokens): self
    {
        return new self(
            'authenticated',
            tokenType: $tokens->tokenType,
            accessToken: $tokens->accessToken,
            accessTokenExpiresIn: $tokens->accessTokenExpiresIn,
            refreshToken: $tokens->refreshToken,
            refreshTokenExpiresIn: $tokens->refreshTokenExpiresIn,
            sessionId: $tokens->sessionId,
            onboardingRequired: $tokens->onboardingRequired,
        );
    }

    public static function pending(PendingSocialSignup $pending, string $rawToken): self
    {
        return new self(
            'pending_signup',
            pendingSignupToken: $rawToken,
            provider: $pending->getProvider(),
            email: $pending->getProviderEmail(),
            displayName: $pending->getDisplayName(),
            expiresAt: $pending->getExpiresAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
