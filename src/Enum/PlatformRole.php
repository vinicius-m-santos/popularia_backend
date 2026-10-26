<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Papel na plataforma (não confundir com o papel dentro de uma comuna, ComunaRole).
 * Só muda pelo comando app:user:set-role; a API nunca aceita este campo do cliente.
 */
enum PlatformRole: string
{
    case User = 'user';
    /** Moderação da plataforma: denúncias de perfis e casos sem comuna (etapa de moderação). */
    case PlatformModerator = 'platform_moderator';
    /** Operação: registros de segurança, preservação de evidências, papéis. */
    case Admin = 'admin';

    /** @return list<string> */
    public function securityRoles(): array
    {
        return match ($this) {
            self::User => ['ROLE_USER'],
            self::PlatformModerator => ['ROLE_PLATFORM_MODERATOR'],
            self::Admin => ['ROLE_ADMIN'],
        };
    }
}
