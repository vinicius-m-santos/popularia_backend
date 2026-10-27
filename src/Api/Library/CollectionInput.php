<?php

declare(strict_types=1);

namespace App\Api\Library;

use App\Api\Constraint\TextRules;
use Symfony\Component\Validator\Constraints as Assert;

final class CollectionInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public string $name = '';

    #[Assert\Length(max: 500)]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public string $description = '';

    #[Assert\Regex('/^[a-z0-9-]{1,100}$/')]
    public ?string $comunaSlug = null;
}
