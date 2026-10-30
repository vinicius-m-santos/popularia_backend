<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EventFormat;
use App\Enum\EventStatus;
use App\Repository\ComunaEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Atividade de uma comuna. O horário é um instante absoluto; timeZone é o fuso anunciado.
 * Cancelar preserva a linha. Passado se calcula de endsAt ou startsAt.
 */
#[ORM\Entity(repositoryClass: ComunaEventRepository::class)]
#[ORM\Table(name: 'comuna_event')]
#[ORM\Index(name: 'idx_comuna_event_when', columns: ['comuna_id', 'starts_at', 'id'])]
class ComunaEvent
{
    public const int TITLE_MAX = 120;
    public const int DESCRIPTION_MAX = 2000;
    public const int PLACE_MAX = 120;
    public const int ADDRESS_MAX = 240;
    public const int URL_MAX = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $revision = 0;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Comuna $comuna,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $organizer,
        #[ORM\Column(length: self::TITLE_MAX)]
        private string $title,
        #[ORM\Column(type: Types::TEXT)]
        private string $description,
        #[ORM\Column(length: 20, enumType: EventFormat::class)]
        private EventFormat $format,
        #[ORM\Column(length: self::PLACE_MAX, nullable: true)]
        private ?string $placeName,
        #[ORM\Column(length: self::ADDRESS_MAX, nullable: true)]
        private ?string $address,
        #[ORM\Column(length: self::URL_MAX, nullable: true)]
        private ?string $onlineUrl,
        #[ORM\Column(length: 64)]
        private string $timeZone,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $startsAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $endsAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->updatedAt = $createdAt;
    }

    #[ORM\Column(length: 20, enumType: EventStatus::class)]
    private EventStatus $status = EventStatus::Scheduled;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function revise(
        string $title,
        string $description,
        EventFormat $format,
        ?string $placeName,
        ?string $address,
        ?string $onlineUrl,
        \DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        \DateTimeImmutable $now,
    ): void {
        $this->title = $title;
        $this->description = $description;
        $this->format = $format;
        $this->placeName = $placeName;
        $this->address = $address;
        $this->onlineUrl = $onlineUrl;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->updatedAt = $now;
        ++$this->revision;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        $this->status = EventStatus::Cancelled;
        $this->cancelledAt = $now;
        $this->updatedAt = $now;
        ++$this->revision;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getComuna(): Comuna
    {
        return $this->comuna;
    }

    public function getOrganizer(): ?User
    {
        return $this->organizer;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getFormat(): EventFormat
    {
        return $this->format;
    }

    public function getPlaceName(): ?string
    {
        return $this->placeName;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getOnlineUrl(): ?string
    {
        return $this->onlineUrl;
    }

    public function getTimeZone(): string
    {
        return $this->timeZone;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getStatus(): EventStatus
    {
        return $this->status;
    }

    public function isCancelled(): bool
    {
        return EventStatus::Cancelled === $this->status;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }
}
