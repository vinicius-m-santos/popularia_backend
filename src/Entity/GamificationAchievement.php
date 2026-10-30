<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Conquista desbloqueada. A chave (conta, código) impede o segundo unlock. */
#[ORM\Entity]
#[ORM\Table(name: 'gamification_achievement')]
class GamificationAchievement
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Id]
        #[ORM\Column(length: 40)]
        private string $code,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $unlockedAt,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getUnlockedAt(): \DateTimeImmutable
    {
        return $this->unlockedAt;
    }
}
