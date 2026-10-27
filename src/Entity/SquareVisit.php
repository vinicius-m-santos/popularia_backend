<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Uma linha por pessoa; presença expira sem depender de saída explícita. */
#[ORM\Entity]
#[ORM\Table(name: 'square_visit')]
#[ORM\UniqueConstraint(name: 'uniq_square_visit', columns: ['square_id', 'user_id'])]
#[ORM\Index(name: 'idx_square_visit_presence', columns: ['square_id', 'seen_at'])]
class SquareVisit
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    public ?Uuid $lastReadId = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $seenAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Square $square,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $user,
    ) {
        $this->id = Uuid::v7();
    }
}
