<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Arquivo privado preparado para ser anexado, uma única vez, a uma publicação. */
#[ORM\Entity]
#[ORM\Table(name: 'post_media')]
#[ORM\UniqueConstraint(name: 'uniq_post_media_upload', columns: ['uploaded_by_id', 'client_id'])]
#[ORM\UniqueConstraint(name: 'uniq_post_media_storage_key', columns: ['storage_key'])]
#[ORM\Index(name: 'idx_post_media_cleanup', columns: ['status', 'created_at'])]
class PostMedia
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

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
        #[ORM\Column(length: 10)]
        public string $kind,
        #[ORM\Column(length: 64)]
        public string $mimeType,
        #[ORM\Column(length: 64)]
        public string $checksum,
        #[ORM\Column]
        public int $byteSize,
        #[ORM\Column]
        public int $width,
        #[ORM\Column]
        public int $height,
        #[ORM\Column(nullable: true)]
        public ?int $durationMs,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }
}
