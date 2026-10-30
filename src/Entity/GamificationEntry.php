<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Uma concessão. A origem é um identificador opaco: a API não devolve esta tabela. */
#[ORM\Entity]
#[ORM\Table(name: 'gamification_entry')]
#[ORM\UniqueConstraint(name: 'uniq_gamification_entry_source', columns: ['user_id', 'action', 'source_type', 'source_id'])]
#[ORM\Index(name: 'idx_gamification_entry_user_action_day', columns: ['user_id', 'action', 'civil_date'])]
class GamificationEntry
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 40)]
        private string $action,
        #[ORM\Column(length: 40)]
        private string $sourceType,
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $sourceId,
        #[ORM\Column]
        private int $xp,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $civilDate,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
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

    public function getAction(): string
    {
        return $this->action;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceId(): Uuid
    {
        return $this->sourceId;
    }

    public function getXp(): int
    {
        return $this->xp;
    }

    public function getCivilDate(): \DateTimeImmutable
    {
        return $this->civilDate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
