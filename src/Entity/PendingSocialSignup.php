<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PendingSocialSignupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Cadastro social iniciado, mas ainda não criado. Guarda apenas o necessário para
 * concluir o perfil depois; o token entregue ao app é armazenado como hash e expira.
 */
#[ORM\Entity(repositoryClass: PendingSocialSignupRepository::class)]
#[ORM\Table(name: 'pending_social_signup')]
#[ORM\UniqueConstraint(name: 'uniq_pending_social_token_hash', columns: ['token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_pending_social_provider_subject', columns: ['provider', 'provider_subject'])]
#[ORM\Index(name: 'idx_pending_social_expires_at', columns: ['expires_at'])]
class PendingSocialSignup
{
    public const int TTL_HOURS = 24;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 20)]
    private string $provider;

    #[ORM\Column(length: 255)]
    private string $providerSubject;

    #[ORM\Column(length: 320, nullable: true)]
    private ?string $providerEmail;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $displayName;

    #[ORM\Column]
    private bool $emailVerified;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $tokenHash,
        string $provider,
        string $providerSubject,
        ?string $providerEmail,
        ?string $displayName,
        bool $emailVerified,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->tokenHash = $tokenHash;
        $this->provider = $provider;
        $this->providerSubject = $providerSubject;
        $this->providerEmail = null !== $providerEmail ? mb_strtolower(trim($providerEmail)) : null;
        $this->displayName = null !== $displayName && '' !== trim($displayName) ? trim($displayName) : null;
        $this->emailVerified = $emailVerified;
        $this->createdAt = $now;
        $this->expiresAt = $now->modify('+'.self::TTL_HOURS.' hours');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
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

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
