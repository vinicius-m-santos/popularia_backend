<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Entidade própria: estrutural na Comuna, vínculo nullable para Praças independentes futuras. */
#[ORM\Entity]
#[ORM\Table(name: 'square')]
#[ORM\UniqueConstraint(name: 'uniq_square_comuna', columns: ['comuna_id'])]
class Square
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(length: 20)]
    public string $status = 'active';

    #[ORM\Column(type: Types::BIGINT)]
    public int $version = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public ?Comuna $comuna,
        /** Null somente quando não há autoria histórica conhecida (equipe/legado). */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
        public ?User $createdBy,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,
        #[ORM\Column(length: 80, nullable: true)]
        public ?string $title = null,
    ) {
        $this->id = Uuid::v7();
        $this->updatedAt = $createdAt;
    }

    public function advance(\DateTimeImmutable $now): int
    {
        $this->updatedAt = $now;

        return ++$this->version;
    }

    /** A Praça estrutural acompanha a Comuna; não é uma configuração da moderação. */
    public function synchronizeWithComuna(\DateTimeImmutable $now): bool
    {
        if (null === $this->comuna) {
            return false;
        }

        $status = $this->comuna->isArchived() ? 'closed' : 'active';
        $closedAt = 'closed' === $status ? ($this->comuna->getArchivedAt() ?? $this->closedAt ?? $now) : null;

        return $this->transition($status, $closedAt, $now);
    }

    /** Reservado ao lifecycle de Praças independentes futuras, sem operação pública na V1. */
    public function close(\DateTimeImmutable $now): void
    {
        $this->assertIndependentLifecycle();
        $this->transition('closed', $this->closedAt ?? $now, $now);
    }

    public function reopen(\DateTimeImmutable $now): void
    {
        $this->assertIndependentLifecycle();
        $this->transition('active', null, $now);
    }

    private function assertIndependentLifecycle(): void
    {
        if (null !== $this->comuna) {
            throw new \LogicException('O lifecycle desta Praça é controlado pela Comuna.');
        }
    }

    private function transition(string $status, ?\DateTimeImmutable $closedAt, \DateTimeImmutable $now): bool
    {
        if ($this->status === $status && $this->closedAt == $closedAt) {
            return false;
        }
        $this->status = $status;
        $this->closedAt = $closedAt;
        $this->advance($now);

        return true;
    }
}
