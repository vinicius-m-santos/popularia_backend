<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'library_share')]
#[ORM\UniqueConstraint(name: 'uniq_library_share', columns: ['document_id', 'comuna_id'])]
#[ORM\Index(name: 'idx_library_share_comuna', columns: ['comuna_id', 'document_id'])]
class LibraryShare
{
    #[ORM\Column(length: 20, enumType: \App\Enum\ContentStatus::class, options: ['default' => 'published'])]
    public \App\Enum\ContentStatus $status = \App\Enum\ContentStatus::Published;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public LibraryDocument $document,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Comuna $comuna,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public ComunaMembership $membership,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }
}
