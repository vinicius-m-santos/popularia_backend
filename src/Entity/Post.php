<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContentStatus;
use App\Enum\Topic;
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
#[ORM\UniqueConstraint(name: 'uniq_post_media_attachment', columns: ['media_id'])]
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

    #[ORM\Column(length: 20, nullable: true, enumType: Topic::class)]
    private ?Topic $topic = null;

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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    public ?LibraryDocument $libraryDocument = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    public ?PostMedia $media = null;

    /** Enquete opcional. Some junto com a publicação quando a autoria apaga o texto. */
    #[ORM\OneToOne(mappedBy: 'post', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?Poll $poll = null;

    public function setTopic(?Topic $topic): void
    {
        if (null !== $this->comuna && null !== $topic) {
            throw new \InvalidArgumentException('O assunto é herdado da comuna.');
        }
        $this->topic = $topic;
    }

    public function getTopic(): ?string
    {
        return null !== $this->comuna ? ($this->comuna->getTopics()[0] ?? null) : $this->topic?->value;
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

    public function getPoll(): ?Poll
    {
        return $this->poll;
    }

    public function attachPoll(Poll $poll): void
    {
        if (null !== $this->poll) {
            throw new \LogicException('A publicação já tem enquete.');
        }
        $this->poll = $poll;
    }

    public function detachPoll(): void
    {
        $this->poll = null;
    }

    /** Apagado pela autoria: o texto sai do banco; a linha fica para não quebrar as conversas. */
    public function deleteByAuthor(): void
    {
        $this->status = ContentStatus::Deleted;
        $this->body = '';
        if (null !== $this->media) {
            $this->media->status = 'purge_pending';
            $this->media = null;
        }
    }

    /**
     * Removido pela moderação da comuna: some para todos. O texto fica no banco (não é exibido)
     * como registro da decisão, para a futura etapa de recursos (docs/moderacao.md).
     */
    public function removeByModeration(): void
    {
        $this->status = ContentStatus::Removed;
    }
}
