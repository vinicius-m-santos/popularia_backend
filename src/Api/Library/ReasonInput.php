<?php

declare(strict_types=1);

namespace App\Api\Library;

use Symfony\Component\Validator\Constraints as Assert;

final class ReasonInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 500)]
    public string $reason = '';

    #[Assert\Regex('/^[a-z0-9-]{1,100}$/')]
    public ?string $comunaSlug = null;
}
