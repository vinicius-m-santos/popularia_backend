<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'sticker_pack')]
#[ORM\UniqueConstraint(name: 'uniq_sticker_pack_comuna', columns: ['comuna_id'])]
#[ORM\UniqueConstraint(name: 'uniq_sticker_pack_global', columns: ['scope'], options: ['where' => "scope = 'popularia'"])]
class StickerPack
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public ?Comuna $comuna,
        #[ORM\Column(length: 20)]
        public string $scope,
        #[ORM\Column(length: 80)]
        public string $name,
    ) {
        $this->id = Uuid::v7();
    }
}
