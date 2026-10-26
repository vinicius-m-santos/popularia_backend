<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\User;

/**
 * A própria conta. É a única resposta com e-mail, interesses e papel: perfis de outras
 * pessoas usam App\Api\Profile\PublicProfile. A conta vem sempre do token, nunca de um ID.
 */
#[ApiResource(
    shortName: 'Me',
    operations: [
        new Get(
            uriTemplate: '/me',
            security: "is_granted('ROLE_USER')",
            provider: MeProvider::class,
            openapi: new Operation(tags: ['Conta'], summary: 'Dados da conta autenticada.'),
        ),
        new Patch(
            uriTemplate: '/me',
            security: "is_granted('ROLE_USER')",
            input: MeUpdate::class,
            processor: MeUpdateProcessor::class,
            openapi: new Operation(
                tags: ['Conta'],
                summary: 'Atualiza nome, bio, cidade e interesses da conta autenticada.',
                description: 'Campos ausentes ou nulos não mudam. Campos desconhecidos (como id, handle, email ou papel) são recusados com 400.',
            ),
        ),
    ],
)]
final readonly class Me
{
    /** @param list<string> $interests */
    public function __construct(
        public string $id,
        public string $handle,
        public string $displayName,
        public string $email,
        public bool $emailVerified,
        public string $bio,
        public ?string $city,
        public array $interests,
        /** user, platform_moderator ou admin. Só leitura. */
        public string $platformRole,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self(
            $user->getId()->toRfc4122(),
            $user->getHandle(),
            $user->getDisplayName(),
            $user->getEmail(),
            $user->isEmailVerified(),
            $user->getBio(),
            $user->getCity(),
            $user->getInterests(),
            $user->getPlatformRole()->value,
            $user->getCreatedAt(),
            $user->getUpdatedAt(),
        );
    }
}
