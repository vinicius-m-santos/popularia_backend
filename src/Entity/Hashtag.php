<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'hashtag')]
#[ORM\UniqueConstraint(name: 'uniq_hashtag_name', columns: ['normalized_name'])]
class Hashtag
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(name: 'normalized_name', length: 50)]
    public string $normalizedName;

    #[ORM\Column(name: 'display_name', length: 50)]
    public string $displayName;

    public function __construct(string $normalizedName, string $displayName)
    {
        $this->id = Uuid::v7();
        $this->normalizedName = $normalizedName;
        $this->displayName = $displayName;
    }
}
