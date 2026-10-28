<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContentStatus;
use App\Enum\LibraryAccess;
use App\Enum\Topic;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'library_document')]
#[ORM\Index(name: 'idx_library_document_collection', columns: ['collection_id', 'status', 'id'])]
#[ORM\Index(name: 'idx_library_document_user', columns: ['user_id', 'status', 'id'])]
#[ORM\Index(name: 'idx_library_document_comuna', columns: ['comuna_id', 'status', 'id'])]
#[ORM\Index(name: 'idx_library_document_public', columns: ['is_public', 'status', 'id'])]
#[ORM\Index(name: 'idx_library_document_topic', columns: ['topic', 'status', 'id'])]
class LibraryDocument
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(length: 20, enumType: ContentStatus::class)]
    public ContentStatus $status = ContentStatus::Published;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $removedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?User $removedBy = null;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $removalReason = null;

    #[ORM\Column(length: 200, nullable: true)]
    public ?string $author = null;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        public LibraryFile $file,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        public LibraryCollection $collection,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
        public ?User $user,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
        public ?Comuna $comuna,
        #[ORM\Column(length: 160)]
        public string $title,
        #[ORM\Column(length: 1000)]
        public string $description,
        #[ORM\Column(length: 20, enumType: LibraryAccess::class)]
        public LibraryAccess $access,
        #[ORM\Column(options: ['default' => false])]
        public bool $isPublic,
        #[ORM\Column(length: 20, nullable: true, enumType: Topic::class)]
        public ?Topic $topic,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,
        ?string $author = null,
    ) {
        $this->id = Uuid::v7();
        $this->updatedAt = $createdAt;
        $this->author = $author;
    }
}
