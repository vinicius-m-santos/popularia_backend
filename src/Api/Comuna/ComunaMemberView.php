<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use App\Entity\ComunaMembership;

/**
 * Pessoa numa comuna: só dados públicos de perfil e o papel (member, moderator, founder).
 * Objeto de saída simples (não é recurso do API Platform): assim não depende de um endereço
 * próprio e funciona com qualquer @, inclusive os que têm ponto.
 */
final readonly class ComunaMemberView
{
    public function __construct(
        public string $id,
        public string $handle,
        public string $displayName,
        /** member, moderator ou founder (proprietário). */
        public string $role,
        public \DateTimeImmutable $joinedAt,
    ) {
    }

    public static function fromMembership(ComunaMembership $membership): self
    {
        $user = $membership->getUser();

        return new self(
            $user->getId()->toRfc4122(),
            $user->getHandle(),
            $user->getDisplayName(),
            $membership->getRole()->value,
            $membership->getJoinedAt(),
        );
    }
}
