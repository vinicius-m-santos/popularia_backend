<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContentStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'square_message')]
#[ORM\Index(name: 'idx_square_message_history', columns: ['square_id', 'id'])]
#[ORM\Index(name: 'idx_square_message_activity', columns: ['square_id', 'created_at'], options: ['where' => "status = 'published'"])]
#[ORM\Index(name: 'idx_square_message_changes', columns: ['square_id', 'revision'])]
#[ORM\UniqueConstraint(name: 'uniq_square_message_scope', columns: ['id', 'square_id'])]
#[ORM\UniqueConstraint(name: 'uniq_square_message_revision', columns: ['square_id', 'revision'])]
#[ORM\UniqueConstraint(name: 'uniq_square_message_client', columns: ['square_id', 'author_id', 'client_id'])]
class SquareMessage
{
    public const int BODY_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(length: 20, enumType: ContentStatus::class)]
    public ContentStatus $status = ContentStatus::Published;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $editedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Square $square,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $author,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public ?string $body,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
        public ?Sticker $sticker,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public ?SquareMessage $replyTo,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::BIGINT)]
        public int $revision,
        #[ORM\Column(length: 64)]
        public string $clientId,
    ) {
        $this->id = Uuid::v7();
    }
}
