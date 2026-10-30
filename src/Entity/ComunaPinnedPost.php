<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ComunaPinnedPostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Destaque de uma publicação normal dentro de uma comuna. Não é outro tipo de conteúdo:
 * a publicação continua sendo um post, e o pin só guarda comuna, ordem e quem fixou.
 * No máximo MAX_PER_COMUNA por comuna (aplicação + gatilho no banco).
 */
#[ORM\Entity(repositoryClass: ComunaPinnedPostRepository::class)]
#[ORM\Table(name: 'comuna_pinned_post')]
#[ORM\UniqueConstraint(name: 'uniq_comuna_pinned_post', columns: ['comuna_id', 'post_id'])]
#[ORM\Index(name: 'idx_comuna_pinned_post_order', columns: ['comuna_id', 'position'])]
#[ORM\Index(name: 'idx_comuna_pinned_post_post', columns: ['post_id'])]
#[ORM\Index(name: 'idx_comuna_pinned_post_pinned_by', columns: ['pinned_by_id'])]
class ComunaPinnedPost
{
    public const int MAX_PER_COMUNA = 3;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Comuna $comuna,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Post $post,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $position,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $pinnedBy,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $pinnedAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getComuna(): Comuna
    {
        return $this->comuna;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getPinnedBy(): ?User
    {
        return $this->pinnedBy;
    }

    public function getPinnedAt(): \DateTimeImmutable
    {
        return $this->pinnedAt;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }
}
