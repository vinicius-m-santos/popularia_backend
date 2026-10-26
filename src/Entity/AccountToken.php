<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountTokenPurpose;
use App\Repository\AccountTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Código de uso único enviado por e-mail (verificação de e-mail ou recuperação de senha).
 * Só o HMAC do código fica no banco. Tem validade curta, limite de tentativas e é fechado
 * ao ser usado ou ao ser substituído por um novo código.
 */
#[ORM\Entity(repositoryClass: AccountTokenRepository::class)]
#[ORM\Table(name: 'account_token')]
#[ORM\Index(name: 'idx_account_token_user_purpose', columns: ['user_id', 'purpose'])]
class AccountToken
{
    public const int MAX_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 30, enumType: AccountTokenPurpose::class)]
    private AccountTokenPurpose $purpose;

    #[ORM\Column(length: 64)]
    private string $codeHash;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(options: ['default' => 0])]
    private int $failedAttempts = 0;

    /** Preenchido quando o código é usado, substituído ou bloqueado por excesso de tentativas. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(User $user, AccountTokenPurpose $purpose, string $codeHash, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->purpose = $purpose;
        $this->codeHash = $codeHash;
        $this->createdAt = $now;
        $this->expiresAt = $now->modify(\sprintf('+%d seconds', $purpose->ttlSeconds()));
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPurpose(): AccountTokenPurpose
    {
        return $this->purpose;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return null === $this->closedAt && $this->expiresAt > $now && $this->failedAttempts < self::MAX_ATTEMPTS;
    }

    public function registerFailedAttempt(\DateTimeImmutable $now): void
    {
        ++$this->failedAttempts;
        if ($this->failedAttempts >= self::MAX_ATTEMPTS) {
            $this->closedAt = $now;
        }
    }

    public function close(\DateTimeImmutable $now): void
    {
        $this->closedAt ??= $now;
    }
}
