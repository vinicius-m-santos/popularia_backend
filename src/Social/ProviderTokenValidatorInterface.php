<?php

declare(strict_types=1);

namespace App\Social;

interface ProviderTokenValidatorInterface
{
    /**
     * Valida o token do provider e retorna as claims verificadas.
     *
     * @throws \InvalidArgumentException quando o token for inválido, expirado ou malformado
     */
    public function validate(#[\SensitiveParameter] string $token, #[\SensitiveParameter] ?string $nonce = null): ProviderClaims;
}
