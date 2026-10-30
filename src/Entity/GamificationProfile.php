<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Totais da conta. O histórico fica em gamification_entry; aqui só o que a UI lê. */
#[ORM\Entity]
#[ORM\Table(name: 'gamification_profile')]
class GamificationProfile
{
    #[ORM\Column(options: ['default' => 0])]
    private int $xpTotal = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $streakCurrent = 0;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastActivityOn = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getXpTotal(): int
    {
        return $this->xpTotal;
    }

    public function getStreakCurrent(): int
    {
        return $this->streakCurrent;
    }

    public function getLastActivityOn(): ?\DateTimeImmutable
    {
        return $this->lastActivityOn;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
