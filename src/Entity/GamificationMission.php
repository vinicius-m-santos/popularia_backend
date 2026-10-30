<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** As missões de um dia civil. Depois de gravadas, não são trocadas. */
#[ORM\Entity]
#[ORM\Table(name: 'gamification_mission')]
#[ORM\UniqueConstraint(name: 'uniq_gamification_mission_slot', columns: ['user_id', 'civil_date', 'slot'])]
#[ORM\UniqueConstraint(name: 'uniq_gamification_mission_action', columns: ['user_id', 'civil_date', 'action'])]
class GamificationMission
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $civilDate,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $slot,
        #[ORM\Column(length: 40)]
        private string $action,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCivilDate(): \DateTimeImmutable
    {
        return $this->civilDate;
    }

    public function getSlot(): int
    {
        return $this->slot;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
}
