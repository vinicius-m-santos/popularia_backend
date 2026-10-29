<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\User;
use App\Media\StoredImageUrl;

/**
 * Perfil público de uma pessoa. Só campos que ela escolheu mostrar: nunca e-mail,
 * interesses, estado da conta, papel ou datas internas além do "na Popularia desde".
 */
#[ApiResource(
    shortName: 'Profile',
    operations: [
        new Get(
            uriTemplate: '/users/{handle}',
            provider: PublicProfileProvider::class,
            openapi: new Operation(
                tags: ['Perfis'],
                summary: 'Perfil público pelo @.',
                description: 'Contas suspensas, inexistentes ou com bloqueio entre as pessoas respondem 404.',
            ),
        ),
    ],
)]
final readonly class PublicProfile
{
    public function __construct(
        #[ApiProperty(identifier: false)]
        public string $id,
        #[ApiProperty(identifier: true)]
        public string $handle,
        public string $displayName,
        public string $bio,
        public ?string $city,
        public \DateTimeImmutable $memberSince,
        public bool $isPrivate = false,
        public int $followerCount = 0,
        public int $followingCount = 0,
        /** Relação de quem consulta. Falso na própria conta e para quem não está autenticado. */
        public bool $viewerFollowing = false,
        public bool $viewerRequested = false,
        /** URL da foto atual. Null quando a pessoa ainda não enviou uma foto. */
        public ?string $avatarUrl = null,
    ) {
    }

    public static function fromUser(User $user, int $followerCount = 0, int $followingCount = 0, bool $viewerFollowing = false, bool $viewerRequested = false): self
    {
        return new self(
            $user->getId()->toRfc4122(),
            $user->getHandle(),
            $user->getDisplayName(),
            $user->getBio(),
            $user->getCity(),
            $user->getCreatedAt(),
            $user->isPrivate(),
            $followerCount,
            $followingCount,
            $viewerFollowing,
            $viewerRequested,
            StoredImageUrl::avatar($user),
        );
    }
}
