<?php

declare(strict_types=1);

namespace App\Api\Square;

use Symfony\Component\Validator\Constraints as Assert;

final class ReadInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $messageId = '';
}
