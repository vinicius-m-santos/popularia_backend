<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserFollowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserFollowRepository::class)]
#[ORM\Table(name: 'user_follow')]
#[ORM\UniqueConstraint(name: 'uniq_user_follow', columns: ['follower_id', 'followed_id'])]
class UserFollow
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $follower,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $followed,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        if ($follower->getId()->equals($followed->getId())) {
            throw new \InvalidArgumentException('Não é possível seguir a própria conta.');
        }
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }
}
