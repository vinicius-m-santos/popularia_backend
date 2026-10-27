<?php

declare(strict_types=1);

namespace App\Api\Square;

use App\Api\Constraint\TextRules;
use App\Square\SquareService;
use Symfony\Component\Validator\Constraints as Assert;

final class StickerInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 24)]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public string $label = '';

    #[Assert\Choice(choices: SquareService::SYMBOLS)]
    public string $symbol = '';

    #[Assert\Choice(choices: ['red', 'black', 'cream'])]
    public string $tone = 'red';
}
