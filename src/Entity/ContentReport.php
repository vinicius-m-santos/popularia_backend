<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Denúncia privada. Quem denunciou fica só no servidor.
 * A unicidade de denúncia aberta (pending/reviewing) é um índice parcial no banco,
 * para permitir nova denúncia depois que a anterior foi decidida.
 */
#[ORM\Entity]
#[ORM\Table(name: 'content_report')]
#[ORM\UniqueConstraint(name: 'uniq_content_report_open', columns: ['reporter_id', 'target_type', 'target_id'], options: ['where' => "status IN ('pending', 'reviewing')"])]
#[ORM\Index(name: 'idx_content_report_queue', columns: ['comuna_id', 'status', 'id'])]
#[ORM\Index(name: 'idx_content_report_platform', columns: ['status', 'id'], options: ['where' => "(scope = 'platform' OR escalated = true)"])]
class ContentReport
{
    public const int SNAPSHOT_MAX = 280;
    public const int DESCRIPTION_MAX = 800;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    /** pending, reviewing, resolved, dismissed ou removed (Praça e Biblioteca). */
    #[ORM\Column(length: 20)]
    public string $status = 'pending';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $resolvedAt = null;

    /** Código estruturado. Nulo nas denúncias antigas de Praça e Biblioteca, que usam só reason. */
    #[ORM\Column(length: 40, nullable: true)]
    public ?string $reasonCode = null;

    /** comuna: fila local. platform: fila da plataforma. Denúncia de comuna inteira é platform e comuna fica nula. */
    #[ORM\Column(length: 16)]
    public string $scope = 'comuna';

    #[ORM\Column(options: ['default' => false])]
    public bool $escalated = false;

    /** Trecho curto no momento da denúncia. Dado privado de moderação. */
    #[ORM\Column(length: self::SNAPSHOT_MAX, nullable: true)]
    public ?string $snapshot = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?User $reviewer = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $claimedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public ?Comuna $comuna,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $reporter,
        #[ORM\Column(length: 32)]
        public string $targetType,
        #[ORM\Column(type: UuidType::NAME)]
        public Uuid $targetId,
        #[ORM\Column(length: self::DESCRIPTION_MAX)]
        public string $reason,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,
        ?string $reasonCode = null,
        ?string $snapshot = null,
    ) {
        $this->id = Uuid::v7();
        $this->reasonCode = $reasonCode;
        $this->snapshot = $snapshot;
        $this->scope = null === $comuna ? 'platform' : 'comuna';
    }

    public function isOpen(): bool
    {
        return \in_array($this->status, ['pending', 'reviewing'], true);
    }
}
