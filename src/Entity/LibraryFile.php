<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Registro de staging persistido antes do PUT; limpeza cobre interrupções e objetos órfãos. */
#[ORM\Entity]
#[ORM\Table(name: 'library_file')]
#[ORM\UniqueConstraint(name: 'uniq_library_upload', columns: ['uploaded_by_id', 'client_id'])]
#[ORM\UniqueConstraint(name: 'uniq_library_storage_key', columns: ['storage_key'])]
class LibraryFile
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(length: 64, options: ['default' => 'application/pdf'])]
    public string $mimeType = 'application/pdf';

    #[ORM\Column(length: 20)]
    public string $status = 'pending';

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        public User $uploadedBy,
        #[ORM\Column(length: 64)]
        public string $clientId,
        #[ORM\Column(length: 255)]
        public string $storageKey,
        #[ORM\Column(length: 255)]
        public string $originalFilename,
        #[ORM\Column(length: 64)]
        public string $checksum,
        #[ORM\Column]
        public int $byteSize,
        #[ORM\Column(nullable: true)]
        public ?int $pageCount,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }
}
