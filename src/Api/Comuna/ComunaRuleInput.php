<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use App\Api\Constraint\TextRules;
use App\Entity\Comuna;
use Symfony\Component\Validator\Constraints as Assert;

final class ComunaRuleInput
{
    #[Assert\NotBlank(message: 'Dê um título para a regra.', normalizer: 'trim')]
    #[Assert\Length(max: Comuna::RULE_TITLE_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public string $title = '';

    #[Assert\Length(max: Comuna::RULE_BODY_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public string $body = '';

    /** @return array{title: string, body: string} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}
