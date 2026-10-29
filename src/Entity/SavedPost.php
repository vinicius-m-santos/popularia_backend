<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SavedPostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Referência privada de uma publicação que a pessoa quer encontrar depois.
 * Não é um documento da Biblioteca, não copia arquivo e não autoriza leitura:
 * a lista passa de novo pela mesma política das outras superfícies.
 */
#[ORM\Entity(repositoryClass: SavedPostRepository::class)]
#[ORM\Table(name: 'saved_post')]
#[ORM\UniqueConstraint(name: 'uniq_saved_post_user_post', columns: ['user_id', 'post_id'])]
#[ORM\Index(name: 'idx_saved_post_user', columns: ['user_id', 'id'])]
#[ORM\Index(name: 'idx_saved_post_post', columns: ['post_id'])]
class SavedPost
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $user,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Post $post,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }
}
