<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Enquete opcional de uma publicação.
 *
 * V1: uma opção por pessoa, o voto pode ser trocado, sem prazo e sem múltipla escolha.
 * A enquete nasce com a publicação e não é editável — não há edição de publicação, e
 * trocar opções depois de existirem votos quebraria o resultado. Apagar a publicação
 * (pela autoria) remove a enquete, as opções e os votos. A remoção por moderação
 * esconde a publicação inteira e conserva o registro, como o texto.
 */
#[ORM\Entity]
#[ORM\Table(name: 'poll')]
#[ORM\UniqueConstraint(name: 'uniq_poll_post', columns: ['post_id'])]
class Poll
{
    public const int QUESTION_MAX_LENGTH = 140;
    public const int OPTION_MAX_LENGTH = 80;
    public const int MIN_OPTIONS = 2;
    public const int MAX_OPTIONS = 6;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** @var Collection<int, PollOption> */
    #[ORM\OneToMany(targetEntity: PollOption::class, mappedBy: 'poll', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $options;

    /** @param list<string> $labels */
    public function __construct(
        #[ORM\OneToOne(inversedBy: 'poll')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Post $post,
        #[ORM\Column(length: self::QUESTION_MAX_LENGTH)]
        private string $question,
        array $labels,
    ) {
        $count = \count($labels);
        if ($count < self::MIN_OPTIONS || $count > self::MAX_OPTIONS) {
            throw new \InvalidArgumentException('Enquete inválida.');
        }
        $this->id = Uuid::v7();
        $this->options = new ArrayCollection();
        foreach ($labels as $position => $label) {
            $this->options->add(new PollOption($this, $position, $label));
        }
        $post->attachPoll($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    /** @return list<PollOption> */
    public function getOptions(): array
    {
        return array_values($this->options->toArray());
    }
}
