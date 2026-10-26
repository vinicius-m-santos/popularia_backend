<?php

declare(strict_types=1);

namespace App\Enum;

enum AccountTokenPurpose: string
{
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';

    /** Validade do código enviado por e-mail. */
    public function ttlSeconds(): int
    {
        return match ($this) {
            self::EmailVerification => 24 * 3600,
            self::PasswordReset => 30 * 60,
        };
    }
}
