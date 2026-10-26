<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\AccountTokenPurpose;
use Symfony\Component\String\ByteString;

/**
 * Geração e hash de segredos.
 *
 * - Tokens de sessão: 43 caracteres alfanuméricos aleatórios (~256 bits). Como têm entropia
 *   alta, um SHA-256 simples basta para guardá-los sem que o banco permita reutilizá-los.
 * - Códigos por e-mail: 6 dígitos, fáceis de digitar no celular. Por terem pouca entropia,
 *   são guardados com HMAC (chave APP_SECRET), presos à conta e à finalidade, e protegidos
 *   por validade curta, limite de tentativas e limite de taxa.
 */
final class TokenGenerator
{
    private const string TOKEN_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct(
        private readonly string $appSecret,
    ) {
    }

    public function newSessionToken(): string
    {
        return ByteString::fromRandom(43, self::TOKEN_ALPHABET)->toString();
    }

    public function hashSessionToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function newCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', \STR_PAD_LEFT);
    }

    public function hashCode(User $user, AccountTokenPurpose $purpose, string $code): string
    {
        return hash_hmac('sha256', $user->getId()->toRfc4122().'|'.$purpose->value.'|'.$code, $this->appSecret);
    }
}
