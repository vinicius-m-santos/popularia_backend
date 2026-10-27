<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'square_reaction')]
#[ORM\UniqueConstraint(name: 'uniq_square_reaction', columns: ['message_id', 'user_id', 'emoji'])]
class SquareReaction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public SquareMessage $message,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $user,
        #[ORM\Column(length: 16)]
        public string $emoji,
    ) {
        $this->id = Uuid::v7();
    }
}
