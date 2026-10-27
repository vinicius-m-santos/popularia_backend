<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserAuthIdentityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Vínculo entre um User Popularia e uma identidade de um provider externo (Google, Apple).
 * O `providerSubject` é o identificador estável do provider (claim `sub`), nunca o e-mail.
 * Um User pode ter múltiplas identidades; cada (provider, providerSubject) pertence a um User.
 */
#[ORM\Entity(repositoryClass: UserAuthIdentityRepository::class)]
#[ORM\Table(name: 'user_auth_identity')]
#[ORM\UniqueConstraint(name: 'uniq_auth_identity_provider_subject', columns: ['provider', 'provider_subject'])]
#[ORM\Index(name: 'idx_auth_identity_user', columns: ['user_id'])]
class UserAuthIdentity
{
    public const string PROVIDER_GOOGLE = 'google';
    public const string PROVIDER_APPLE = 'apple';

    /** @var list<string> */
    public const array VALID_PROVIDERS = [self::PROVIDER_GOOGLE, self::PROVIDER_APPLE];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $provider;

    #[ORM\Column(length: 255)]
    private string $providerSubject;

    /**
     * E-mail que o provider forneceu no primeiro login (pode ser relay da Apple).
     * Guardado para referência, nunca usado como identificador de conta.
     */
    #[ORM\Column(length: 320, nullable: true)]
    private ?string $providerEmail;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $provider, string $providerSubject, ?string $providerEmail, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->provider = $provider;
        $this->providerSubject = $providerSubject;
        $this->providerEmail = null !== $providerEmail ? mb_strtolower(trim($providerEmail)) : null;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderSubject(): string
    {
        return $this->providerSubject;
    }

    public function getProviderEmail(): ?string
    {
        return $this->providerEmail;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
