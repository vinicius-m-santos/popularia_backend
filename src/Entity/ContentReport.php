<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Referência ao conteúdo, sem cópia do texto nem exposição de quem denunciou na fila. */
#[ORM\Entity]
#[ORM\Table(name: 'content_report')]
#[ORM\UniqueConstraint(name: 'uniq_content_report', columns: ['reporter_id', 'target_type', 'target_id'])]
#[ORM\Index(name: 'idx_content_report_queue', columns: ['comuna_id', 'status', 'id'])]
class ContentReport
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column(length: 20)]
    public string $status = 'pending';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Comuna $comuna,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $reporter,
        #[ORM\Column(length: 32)]
        public string $targetType,
        #[ORM\Column(type: UuidType::NAME)]
        public Uuid $targetId,
        #[ORM\Column(length: 500)]
        public string $reason,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }
}
