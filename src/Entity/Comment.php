<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContentStatus;
use App\Repository\CommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Comentário ou resposta (parent) numa publicação. O pai precisa ser da mesma publicação. */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
#[ORM\Index(name: 'idx_comment_post', columns: ['post_id', 'id'])]
class Comment
{
    public const int BODY_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: ContentStatus::class)]
    private ContentStatus $status = ContentStatus::Published;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Post $post,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Comment $parent,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $author,
        #[ORM\Column(type: Types::TEXT)]
        private string $body,
        \DateTimeImmutable $now,
    ) {
        if (null !== $parent && !$parent->getPost()->getId()->equals($post->getId())) {
            throw new \InvalidArgumentException('A resposta precisa ser da mesma publicação do comentário pai.');
        }
        $this->id = Uuid::v7();
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getParent(): ?Comment
    {
        return $this->parent;
    }

    public function getAuthor(): User
    {
        return $this->author;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
