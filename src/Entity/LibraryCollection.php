<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'library_collection')]
#[ORM\Index(name: 'idx_library_collection_user', columns: ['user_id', 'id'])]
#[ORM\Index(name: 'idx_library_collection_comuna', columns: ['comuna_id', 'id'])]
class LibraryCollection
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
        public ?User $user,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public ?Comuna $comuna,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        public User $createdBy,
        #[ORM\Column(length: 100)]
        public string $name,
        #[ORM\Column(length: 500)]
        public string $description,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,
    ) {
        if ((null === $user) === (null === $comuna)) {
            throw new \InvalidArgumentException('Uma coleção tem exatamente uma origem.');
        }
        $this->id = Uuid::v7();
        $this->updatedAt = $createdAt;
    }
}
