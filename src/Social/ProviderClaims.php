<?php

declare(strict_types=1);

namespace App\Social;

/**
 * Claims extraídas e validadas de um identity token de provider (Google ou Apple).
 * Tudo que chegou aqui já foi verificado criptograficamente pelo validador do provider.
 */
final readonly class ProviderClaims
{
    public function __construct(
        /** Identificador estável do provider: claim `sub`. Nunca muda. */
        public string $subject,
        /**
         * E-mail fornecido pelo provider. Pode ser relay/privado (Apple Hide My Email).
         * Não usar como identificador permanente de conta.
         */
        public ?string $email,
        /** O provider confirma que este e-mail pertence ao usuário. */
        public bool $emailVerified,
        /** Nome completo retornado pelo provider (nem sempre disponível, esp. em logins seguintes da Apple). */
        public ?string $name,
    ) {
    }
}
