<?php

declare(strict_types=1);

namespace App\Tests\Social;

use App\Social\ProviderClaims;
use App\Social\ProviderTokenValidatorInterface;

/**
 * Stub do validador Google para testes.
 * Aceita tokens no formato "google_valid|sub|email|email_verified" em vez de JWT real.
 */
class FakeGoogleTokenValidator implements ProviderTokenValidatorInterface
{
    public function validate(#[\SensitiveParameter] string $idToken, #[\SensitiveParameter] ?string $nonce = null): ProviderClaims
    {
        if (!str_starts_with($idToken, 'google_valid|')) {
            throw new \InvalidArgumentException('Token Google inválido (teste).');
        }

        $parts = explode('|', $idToken);
        if (\count($parts) < 4) {
            throw new \InvalidArgumentException('Token Google stub malformado.');
        }

        [, $sub, $email, $emailVerified] = $parts;
        $name = $parts[4] ?? null;

        return new ProviderClaims(
            subject: $sub,
            email: '' !== $email ? $email : null,
            emailVerified: '1' === $emailVerified,
            name: '' !== ($name ?? '') ? $name : null,
        );
    }
}
