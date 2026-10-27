<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountStatus;
use App\Enum\PlatformRole;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Conta de uma pessoa. Contém dados privados (e-mail, senha, interesses, estado):
 * nunca é serializada diretamente. As respostas usam App\Api\Me\Me e App\Api\Profile\PublicProfile.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_app_user_handle', columns: ['handle'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const int HANDLE_MIN_LENGTH = 3;
    public const int HANDLE_MAX_LENGTH = 20;
    public const string HANDLE_PATTERN = '/^[a-z0-9._]{3,20}$/';
    public const int DISPLAY_NAME_MAX_LENGTH = 50;
    public const int BIO_MAX_LENGTH = 160;
    public const int CITY_MAX_LENGTH = 80;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Sempre minúsculo e sem espaços nas pontas (veja normalizeEmail). */
    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: self::HANDLE_MAX_LENGTH)]
    private string $handle;

    #[ORM\Column(length: self::DISPLAY_NAME_MAX_LENGTH)]
    private string $displayName;

    #[ORM\Column(length: self::BIO_MAX_LENGTH, options: ['default' => ''])]
    private string $bio = '';

    #[ORM\Column(length: self::CITY_MAX_LENGTH, nullable: true)]
    private ?string $city = null;

    /** @var list<string> Códigos de App\Enum\Topic. Dado privado. */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $interests = [];

    #[ORM\Column(length: 20, enumType: AccountStatus::class, options: ['default' => 'active'])]
    private AccountStatus $status = AccountStatus::Active;

    #[ORM\Column(length: 30, enumType: PlatformRole::class, options: ['default' => 'user'])]
    private PlatformRole $platformRole = PlatformRole::User;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Última criação efetiva, para histórico. Não restringe a criação de novas comunas.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastComunaCreatedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $email, string $handle, string $displayName, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->email = self::normalizeEmail($email);
        $this->handle = self::normalizeHandle($handle);
        $this->displayName = trim($displayName);
        $this->password = '';
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function normalizeHandle(string $handle): string
    {
        return mb_strtolower(trim($handle));
    }

    #[ORM\Column(options: ['default' => false])]
    private bool $isPrivate = false;

    public function isPrivate(): bool
    {
        return $this->isPrivate;
    }

    public function setPrivate(bool $private, \DateTimeImmutable $now): void
    {
        $this->isPrivate = $private;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function markEmailVerified(\DateTimeImmutable $now): void
    {
        $this->emailVerifiedAt ??= $now;
        $this->updatedAt = $now;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /** Recebe o hash, nunca a senha em texto. */
    public function setPasswordHash(string $hash, \DateTimeImmutable $now): void
    {
        $this->password = $hash;
        $this->updatedAt = $now;
    }

    public function getHandle(): string
    {
        return $this->handle;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getBio(): string
    {
        return $this->bio;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    /** @return list<string> */
    public function getInterests(): array
    {
        return $this->interests;
    }

    /**
     * Atualiza os campos de perfil que a própria pessoa pode mudar.
     * Handle, e-mail, papel e estado não passam por aqui.
     *
     * @param list<string>|null $interests
     */
    public function updateProfile(?string $displayName, ?string $bio, ?string $city, ?array $interests, \DateTimeImmutable $now): void
    {
        if (null !== $displayName) {
            $this->displayName = trim($displayName);
        }
        if (null !== $bio) {
            $this->bio = trim($bio);
        }
        if (null !== $city) {
            $city = trim($city);
            $this->city = '' === $city ? null : $city;
        }
        if (null !== $interests) {
            $this->interests = array_values(array_unique($interests));
        }
        $this->updatedAt = $now;
    }

    public function getStatus(): AccountStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return AccountStatus::Active === $this->status;
    }

    public function changeStatus(AccountStatus $status, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->updatedAt = $now;
    }

    public function getPlatformRole(): PlatformRole
    {
        return $this->platformRole;
    }

    public function changePlatformRole(PlatformRole $role, \DateTimeImmutable $now): void
    {
        $this->platformRole = $role;
        $this->updatedAt = $now;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastComunaCreatedAt(): ?\DateTimeImmutable
    {
        return $this->lastComunaCreatedAt;
    }

    public function recordComunaCreation(\DateTimeImmutable $now): void
    {
        $this->lastComunaCreatedAt = $now;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUserIdentifier(): string
    {
        return $this->id->toRfc4122();
    }

    public function getRoles(): array
    {
        return $this->platformRole->securityRoles();
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
