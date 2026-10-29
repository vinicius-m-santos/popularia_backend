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
#[ORM\Index(name: 'idx_follow_outgoing', columns: ['follower_id', 'approved', 'id'])]
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
        #[ORM\Column(options: ['default' => true])]
        private bool $approved = true,
    ) {
        if ($follower->getId()->equals($followed->getId())) {
            throw new \InvalidArgumentException('Não é possível seguir a própria conta.');
        }
        $this->id = Uuid::v7();
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }

    public function approve(): void
    {
        $this->approved = true;
    }

    public function getFollower(): User
    {
        return $this->follower;
    }

    public function getFollowed(): User
    {
        return $this->followed;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }
}
