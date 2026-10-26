<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'comuna_rule')]
#[ORM\UniqueConstraint(name: 'uniq_comuna_rule_position', columns: ['comuna_id', 'position'])]
class ComunaRule
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'rules')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Comuna $comuna,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $position,
        #[ORM\Column(length: 120)]
        private string $title,
        #[ORM\Column(type: Types::TEXT)]
        private string $body,
    ) {
        $this->id = Uuid::v7();
    }

    public function getComuna(): Comuna
    {
        return $this->comuna;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function change(string $title, string $body): void
    {
        $this->title = $title;
        $this->body = $body;
    }
}
