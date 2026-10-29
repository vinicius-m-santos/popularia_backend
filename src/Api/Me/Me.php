<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\RequestBody;
use App\Entity\User;
use App\Media\StoredImageUrl;

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
        new Post(
            uriTemplate: '/me/avatar',
            security: "is_granted('ROLE_USER')",
            inputFormats: ['multipart' => ['multipart/form-data']],
            controller: AvatarUploadController::class,
            deserialize: false,
            validate: false,
            read: false,
            input: false,
            output: false,
            status: 200,
            openapi: new Operation(
                tags: ['Conta'],
                summary: 'Troca a foto de perfil da conta autenticada.',
                description: 'Somente a própria conta. JPEG, PNG ou WebP estáticos, até 10 MB, com as mesmas regras das imagens de publicação. A URL devolvida muda a cada troca.',
                requestBody: new RequestBody(content: new \ArrayObject([
                    'multipart/form-data' => ['schema' => ['type' => 'object', 'required' => ['file'], 'properties' => [
                        'file' => ['type' => 'string', 'format' => 'binary'],
                    ]]],
                ])),
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
        public ?string $state,
        public ?string $country,
        public string $countryCode,
        public ?string $stateIbgeCode,
        public ?string $municipalityIbgeCode,
        public array $interests,
        /** user, platform_moderator ou admin. Só leitura. */
        public string $platformRole,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public bool $isPrivate = false,
        /** true quando a conta foi criada via social login e ainda não completou o onboarding. */
        public bool $pendingOnboarding = false,
        /** false para contas criadas via Google/Apple sem senha definida. */
        public bool $hasPassword = true,
        public bool $legalAccepted = false,
        public int $followerCount = 0,
        /** URL da foto atual. Null quando a pessoa ainda não enviou uma foto. */
        public ?string $avatarUrl = null,
    ) {
    }

    public static function fromUser(User $user, int $followerCount = 0): self
    {
        return new self(
            $user->getId()->toRfc4122(),
            $user->getHandle(),
            $user->getDisplayName(),
            $user->getEmail(),
            $user->isEmailVerified(),
            $user->getBio(),
            $user->getCity(),
            $user->getState(),
            $user->getCountry(),
            $user->getCountryCode(),
            $user->getStateIbgeCode(),
            $user->getMunicipalityIbgeCode(),
            $user->getInterests(),
            $user->getPlatformRole()->value,
            $user->getCreatedAt(),
            $user->getUpdatedAt(),
            $user->isPrivate(),
            $user->isPendingOnboarding(),
            $user->hasPassword(),
            $user->hasAcceptedLegal(),
            $followerCount,
            StoredImageUrl::avatar($user),
        );
    }
}
