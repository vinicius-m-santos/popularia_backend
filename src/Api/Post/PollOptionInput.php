<?php

declare(strict_types=1);

namespace App\Api\Post;

use App\Api\Constraint\TextRules;
use App\Entity\Poll;
use Symfony\Component\Validator\Constraints as Assert;

final class PollOptionInput
{
    #[Assert\NotBlank(message: 'Preencha esta opção.', normalizer: 'trim')]
    #[Assert\Length(max: Poll::OPTION_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.', normalizer: 'trim')]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public string $label = '';
}
