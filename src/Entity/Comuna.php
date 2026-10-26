<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ComunaVisibility;
use App\Repository\ComunaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Comunidade ("comuna"). No MVP só a equipe cria comunas (comando app:dev:seed em
 * desenvolvimento; ferramenta administrativa depois). memberCount é calculado no servidor.
 */
#[ORM\Entity(repositoryClass: ComunaRepository::class)]
#[ORM\Table(name: 'comuna')]
#[ORM\UniqueConstraint(name: 'uniq_comuna_slug', columns: ['slug'])]
class Comuna
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 40)]
    private string $slug;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /** Lugar declarado ("São Paulo · SP"). Não é localização de ninguém. */
    #[ORM\Column(length: 80)]
    private string $place;

    #[ORM\Column(length: 20, enumType: ComunaVisibility::class)]
    private ComunaVisibility $visibility;

    /** Variação visual do design: red, black ou cream. */
    #[ORM\Column(length: 10)]
    private string $tone;

    /** @var list<string> Códigos de App\Enum\Topic. */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $topics;

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $moderationNote = '';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $foundedYear;

    #[ORM\Column(options: ['default' => 0])]
    private int $memberCount = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, ComunaRule> */
    #[ORM\OneToMany(targetEntity: ComunaRule::class, mappedBy: 'comuna', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $rules;

    /** @param list<string> $topics */
    public function __construct(
        string $slug,
        string $name,
        string $description,
        string $place,
        ComunaVisibility $visibility,
        string $tone,
        array $topics,
        int $foundedYear,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->name = $name;
        $this->description = $description;
        $this->place = $place;
        $this->visibility = $visibility;
        $this->tone = $tone;
        $this->topics = $topics;
        $this->foundedYear = $foundedYear;
        $this->createdAt = $now;
        $this->rules = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPlace(): string
    {
        return $this->place;
    }

    public function getVisibility(): ComunaVisibility
    {
        return $this->visibility;
    }

    public function isPublic(): bool
    {
        return ComunaVisibility::Public === $this->visibility;
    }

    public function getTone(): string
    {
        return $this->tone;
    }

    /** @return list<string> */
    public function getTopics(): array
    {
        return $this->topics;
    }

    public function getModerationNote(): string
    {
        return $this->moderationNote;
    }

    public function setModerationNote(string $note): void
    {
        $this->moderationNote = $note;
    }

    public function getFoundedYear(): int
    {
        return $this->foundedYear;
    }

    public function getMemberCount(): int
    {
        return $this->memberCount;
    }

    /** @return list<ComunaRule> */
    public function getRules(): array
    {
        return array_values($this->rules->toArray());
    }

    public function addRule(string $title, string $body): void
    {
        $this->rules->add(new ComunaRule($this, $this->rules->count() + 1, $title, $body));
    }
}
