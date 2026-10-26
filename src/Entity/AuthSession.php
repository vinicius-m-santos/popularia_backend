<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuthSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Sessão de um aparelho. Guarda só o hash (SHA-256) do access token atual; os refresh
 * tokens ficam em RefreshToken. Não guarda IP: o IP de cada evento fica no registro de
 * segurança (SecurityEvent), que tem retenção limitada.
 */
#[ORM\Entity(repositoryClass: AuthSessionRepository::class)]
#[ORM\Table(name: 'auth_session')]
#[ORM\UniqueConstraint(name: 'uniq_auth_session_access_token', columns: ['access_token_hash'])]
#[ORM\Index(name: 'idx_auth_session_user', columns: ['user_id', 'revoked_at'])]
class AuthSession
{
    public const int DEVICE_LABEL_MAX_LENGTH = 60;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Nome informado pelo app ("iPhone", "Android"). Opcional e sem identificador do aparelho. */
    #[ORM\Column(length: self::DEVICE_LABEL_MAX_LENGTH, nullable: true)]
    private ?string $deviceLabel;

    #[ORM\Column(length: 64)]
    private string $accessTokenHash;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $accessTokenExpiresAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastUsedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $revokedReason = null;

    public function __construct(User $user, ?string $deviceLabel, string $accessTokenHash, \DateTimeImmutable $accessTokenExpiresAt, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->deviceLabel = $deviceLabel;
        $this->accessTokenHash = $accessTokenHash;
        $this->accessTokenExpiresAt = $accessTokenExpiresAt;
        $this->createdAt = $now;
        $this->lastUsedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDeviceLabel(): ?string
    {
        return $this->deviceLabel;
    }

    public function getAccessTokenExpiresAt(): \DateTimeImmutable
    {
        return $this->accessTokenExpiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function getRevokedReason(): ?string
    {
        return $this->revokedReason;
    }

    /** Troca o access token (o anterior deixa de valer na hora). */
    public function rotateAccessToken(string $accessTokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void
    {
        $this->accessTokenHash = $accessTokenHash;
        $this->accessTokenExpiresAt = $expiresAt;
        $this->lastUsedAt = $now;
    }

    public function revoke(string $reason, \DateTimeImmutable $now): void
    {
        if (null !== $this->revokedAt) {
            return;
        }
        $this->revokedAt = $now;
        $this->revokedReason = $reason;
    }
}
