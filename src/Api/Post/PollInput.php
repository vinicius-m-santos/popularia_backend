<?php

declare(strict_types=1);

namespace App\Api\Post;

use App\Api\Constraint\TextRules;
use App\Content\TextNormalizer;
use App\Entity\Poll;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Enquete criada junto com a publicação. Contagens e votos não vêm do cliente. */
final class PollInput
{
    #[Assert\NotBlank(message: 'Escreva a pergunta da enquete.', normalizer: 'trim')]
    #[Assert\Length(max: Poll::QUESTION_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.', normalizer: 'trim')]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public string $question = '';

    /** @var list<PollOptionInput> */
    #[Assert\Count(min: Poll::MIN_OPTIONS, max: Poll::MAX_OPTIONS, minMessage: 'A enquete precisa de pelo menos {{ limit }} opções.', maxMessage: 'A enquete pode ter até {{ limit }} opções.')]
    #[Assert\Valid]
    public array $options = [];

    #[Assert\Callback]
    public function validateDistinctOptions(ExecutionContextInterface $context): void
    {
        $seen = [];
        foreach ($this->options as $index => $option) {
            $key = mb_strtolower(TextNormalizer::normalize($option->label));
            if ('' === $key || !isset($seen[$key])) {
                $seen[$key] = true;
                continue;
            }
            $context->buildViolation('As opções precisam ser diferentes.')
                ->atPath('options['.$index.'].label')
                ->addViolation();
        }
    }
}
