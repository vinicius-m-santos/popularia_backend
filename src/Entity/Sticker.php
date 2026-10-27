<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** V1 simbólica. assetKey será uma referência ao armazenamento oficial, nunca base64/URL arbitrária. */
#[ORM\Entity]
#[ORM\Table(name: 'sticker')]
#[ORM\Index(name: 'idx_sticker_pack_active', columns: ['pack_id', 'active'])]
class Sticker
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column]
    public bool $active = true;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $assetKey = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public StickerPack $pack,
        #[ORM\Column(length: 24)]
        public string $label,
        #[ORM\Column(length: 16)]
        public string $symbol,
        #[ORM\Column(length: 10)]
        public string $tone = 'red',
    ) {
        $this->id = Uuid::v7();
    }
}
