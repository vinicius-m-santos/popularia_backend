<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'poll_option')]
#[ORM\UniqueConstraint(name: 'uniq_poll_option_position', columns: ['poll_id', 'position'])]
class PollOption
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(options: ['default' => 0])]
    private int $voteCount = 0;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'options')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Poll $poll,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $position,
        #[ORM\Column(length: Poll::OPTION_MAX_LENGTH)]
        private string $label,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPoll(): Poll
    {
        return $this->poll;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getVoteCount(): int
    {
        return $this->voteCount;
    }
}
