<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PollVoteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PollVoteRepository::class)]
#[ORM\Table(name: 'poll_vote')]
#[ORM\UniqueConstraint(name: 'uniq_poll_vote_user', columns: ['poll_id', 'user_id'])]
class PollVote
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Poll $poll,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PollOption $option,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
    ) {
        if (!$option->getPoll()->getId()->equals($poll->getId())) {
            throw new \InvalidArgumentException('A opção não pertence à enquete.');
        }
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

    public function getOption(): PollOption
    {
        return $this->option;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function moveTo(PollOption $option): void
    {
        if (!$option->getPoll()->getId()->equals($this->poll->getId())) {
            throw new \InvalidArgumentException('A opção não pertence à enquete.');
        }
        $this->option = $option;
    }
}
