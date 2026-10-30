<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ComunaStatus;
use App\Enum\ComunaVisibility;
use App\Enum\Topic;
use App\Repository\ComunaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Comunidade ("comuna"). Criada por uma pessoa (POST /comunas, que vira a proprietária) ou pela
 * equipe (sem proprietário). Nome e slug não mudam depois de criados: o slug é o endereço estável.
 * memberCount é calculado no servidor.
 *
 * A propriedade fica em owner (fonte da verdade, FK RESTRICT: uma conta que é dona de comunas não
 * pode ser apagada sem antes transferi-las) e é espelhada no papel "founder" da participação; as
 * duas mudam juntas, na mesma transação (ComunaGovernance). O banco garante um único "founder" por
 * comuna e no máximo MAX_ACTIVE_PER_OWNER comunas ativas por proprietário (migration Version20261026200000).
 */
#[ORM\Entity(repositoryClass: ComunaRepository::class)]
#[ORM\Table(name: 'comuna')]
#[ORM\UniqueConstraint(name: 'uniq_comuna_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_comuna_owner_status', columns: ['owner_id', 'status'])]
class Comuna
{
    public const int MAX_ACTIVE_PER_OWNER = 3;
    // Sem cooldown; mantido no contrato de cota para compatibilidade com o app.
    public const int CREATION_INTERVAL_DAYS = 0;
    public const int NAME_MIN_LENGTH = 3;
    public const int NAME_MAX_LENGTH = 60;
    public const int SLUG_MAX_LENGTH = 40;
    public const int DESCRIPTION_MAX_LENGTH = 500;
    public const int PLACE_MAX_LENGTH = 80;
    public const int MAX_RULES = 10;
    public const int RULE_TITLE_MAX_LENGTH = 120;
    public const int RULE_BODY_MAX_LENGTH = 500;
    public const array TONES = ['red', 'black', 'cream'];

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

    /** Quem responde pela comuna. Null = comuna da equipe da plataforma. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $owner = null;

    #[ORM\Column(length: 20, enumType: ComunaStatus::class, options: ['default' => 'active'])]
    private ComunaStatus $status = ComunaStatus::Active;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    /** Objeto atual em comuna-covers/<uuid>.<ext>. Null usa a arte padrão do app. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $coverKey = null;

    /** Objeto atual em comuna-marks/<uuid>.<ext>. Null usa as iniciais da comuna. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $markKey = null;

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
        ?User $owner = null,
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
        $this->owner = $owner;
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

    public function getCoverKey(): ?string
    {
        return $this->coverKey;
    }

    /** Cada troca grava uma key nova para a capa deixar de ser servida pela URL anterior. */
    public function replaceCover(string $key): void
    {
        $this->coverKey = $key;
    }

    public function getMarkKey(): ?string
    {
        return $this->markKey;
    }

    /** Cada troca grava uma key nova para a marca deixar de ser servida pela URL anterior. */
    public function replaceMark(string $key): void
    {
        $this->markKey = $key;
    }

    /** @return list<string> */
    public function getTopics(): array
    {
        return $this->topics;
    }

    /** Um assunto principal; o array legado é preservado até a próxima escolha explícita. */
    public function changeTopic(string $topic): void
    {
        $this->topics = [Topic::from($topic)->value];
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function isOwnedBy(?User $user): bool
    {
        return null !== $user && null !== $this->owner && $this->owner->getId()->equals($user->getId());
    }

    /** Só ComunaGovernance chama, junto com a troca dos papéis "founder" (mesma transação). */
    public function changeOwner(User $owner): void
    {
        $this->owner = $owner;
    }

    public function getStatus(): ComunaStatus
    {
        return $this->status;
    }

    public function isArchived(): bool
    {
        return ComunaStatus::Archived === $this->status;
    }

    public function isActive(): bool
    {
        return ComunaStatus::Active === $this->status;
    }

    public function isSuspended(): bool
    {
        return ComunaStatus::Suspended === $this->status;
    }

    /** Punição da plataforma. Comuna arquivada não entra neste caminho. */
    public function suspend(): void
    {
        if ($this->isArchived()) {
            throw new \LogicException('Uma comuna arquivada não é suspensa por este caminho.');
        }
        $this->status = ComunaStatus::Suspended;
    }

    public function reactivate(): void
    {
        if (!$this->isSuspended()) {
            throw new \LogicException('Só uma comuna suspensa volta a ficar ativa.');
        }
        $this->status = ComunaStatus::Active;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    /** Definitivo pelo app: não há caminho de volta para "active". */
    public function archive(\DateTimeImmutable $now): void
    {
        $this->status = ComunaStatus::Archived;
        $this->archivedAt = $now;
    }

    public function changeDescription(string $description): void
    {
        $this->description = $description;
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

    /**
     * Troca a lista de regras mantendo as posições 1..n. As regras existentes são atualizadas no
     * lugar e só as que sobram são removidas (o Doctrine insere antes de apagar: recriar todas
     * esbarraria na posição única por comuna).
     *
     * @param list<array{title: string, body: string}> $rules
     */
    public function replaceRules(array $rules): void
    {
        $current = $this->getRules();
        foreach ($rules as $i => $rule) {
            if (isset($current[$i])) {
                $current[$i]->change($rule['title'], $rule['body']);
            } else {
                $this->addRule($rule['title'], $rule['body']);
            }
        }
        foreach (\array_slice($current, \count($rules)) as $extra) {
            $this->rules->removeElement($extra);
        }
    }
}
