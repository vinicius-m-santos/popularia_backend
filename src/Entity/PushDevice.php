<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'push_device')]
#[ORM\UniqueConstraint(name: 'uniq_push_device_token', columns: ['token'])]
#[ORM\Index(name: 'idx_push_device_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_push_device_session', columns: ['session_id'])]
class PushDevice
{
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $registeredAt;

    #[ORM\Column(length: 64)]
    public string $revocationHash = '';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        public Uuid $id,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $user,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public AuthSession $session,
        #[ORM\Column(length: 255)]
        public string $token,
        #[ORM\Column(length: 10)]
        public string $platform,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $updatedAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        public ?\DateTimeImmutable $disabledAt = null,
    ) {
        $this->registeredAt = $updatedAt;
    }
}
