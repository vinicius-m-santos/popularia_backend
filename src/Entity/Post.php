<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContentStatus;
use App\Repository\PostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Publicação. A autoria vem sempre do token de quem publicou. Sem comuna = publicada no
 * próprio perfil. O ID é UUID v7 (ordenado pelo tempo), usado também como cursor da paginação.
 */
#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'post')]
#[ORM\Index(name: 'idx_post_comuna', columns: ['comuna_id', 'status', 'id'])]
#[ORM\Index(name: 'idx_post_author', columns: ['author_id', 'id'])]
class Post
{
    public const int BODY_MAX_LENGTH = 1000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: ContentStatus::class)]
    private ContentStatus $status = ContentStatus::Published;

    #[ORM\Column(options: ['default' => 0])]
    private int $commentCount = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $author,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Comuna $comuna,
        #[ORM\Column(type: Types::TEXT)]
        private string $body,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getComuna(): ?Comuna
    {
        return $this->comuna;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatus(): ContentStatus
    {
        return $this->status;
    }

    public function isPublished(): bool
    {
        return ContentStatus::Published === $this->status;
    }

    public function getCommentCount(): int
    {
        return $this->commentCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Apagado pela autoria: o texto sai do banco; a linha fica para não quebrar as conversas. */
    public function deleteByAuthor(): void
    {
        $this->status = ContentStatus::Deleted;
        $this->body = '';
    }
}
