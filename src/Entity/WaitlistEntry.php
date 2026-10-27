<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'waitlist_entry')]
#[ORM\UniqueConstraint(name: 'uq_waitlist_email', columns: ['email'])]
class WaitlistEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 320)]
    private string $email;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $source;

    public function __construct(string $email, \DateTimeImmutable $createdAt, ?string $source = null)
    {
        $this->email = $email;
        $this->createdAt = $createdAt;
        $this->source = $source;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }
}
