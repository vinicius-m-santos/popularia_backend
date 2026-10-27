<?php

declare(strict_types=1);

namespace App\Tests\Social;

use App\Social\ProviderClaims;
use App\Social\ProviderTokenValidatorInterface;

/**
 * Stub do validador Apple para testes.
 * Aceita tokens no formato "apple_valid|sub|email|email_verified" em vez de JWT real.
 */
class FakeAppleTokenValidator implements ProviderTokenValidatorInterface
{
    public function validate(#[\SensitiveParameter] string $identityToken, #[\SensitiveParameter] ?string $rawNonce = null): ProviderClaims
    {
        if (null === $rawNonce || '' === $rawNonce) {
            throw new \InvalidArgumentException('Nonce obrigatório (teste).');
        }

        if (!str_starts_with($identityToken, 'apple_valid|')) {
            throw new \InvalidArgumentException('Token Apple inválido (teste).');
        }

        $parts = explode('|', $identityToken);
        if (\count($parts) < 4) {
            throw new \InvalidArgumentException('Token Apple stub malformado.');
        }

        [, $sub, $email, $emailVerified] = $parts;

        return new ProviderClaims(
            issuedAt: new \DateTimeImmutable(),
            subject: $sub,
            email: '' !== $email ? $email : null,
            emailVerified: '1' === $emailVerified,
            name: null,
        );
    }
}
