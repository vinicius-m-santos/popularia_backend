<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentMentionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Menção escolhida no editor. A identidade é o usuário, não o handle.
 * start/end são offsets em pontos de código Unicode do texto já normalizado.
 */
#[ORM\Entity(repositoryClass: ContentMentionRepository::class)]
#[ORM\Table(name: 'content_mention')]
#[ORM\Index(name: 'idx_content_mention_user', columns: ['mentioned_user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_content_mention_post_start', columns: ['post_id', 'start_offset'])]
#[ORM\UniqueConstraint(name: 'uniq_content_mention_comment_start', columns: ['comment_id', 'start_offset'])]
class ContentMention
{
    public const int MAX_PER_CONTENT = 10;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Post $post,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Comment $comment,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $mentionedUser,
        #[ORM\Column]
        private int $startOffset,
        #[ORM\Column]
        private int $endOffset,
    ) {
        if ((null === $post) === (null === $comment)) {
            throw new \InvalidArgumentException('A menção pertence a uma publicação ou a um comentário.');
        }
        if ($startOffset < 0 || $endOffset <= $startOffset) {
            throw new \InvalidArgumentException('Intervalo de menção inválido.');
        }
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPost(): ?Post
    {
        return $this->post;
    }

    public function getComment(): ?Comment
    {
        return $this->comment;
    }

    public function getMentionedUser(): User
    {
        return $this->mentionedUser;
    }

    public function getStartOffset(): int
    {
        return $this->startOffset;
    }

    public function getEndOffset(): int
    {
        return $this->endOffset;
    }
}
