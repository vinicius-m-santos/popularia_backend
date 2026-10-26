<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use Symfony\Component\Validator\Constraints as Assert;

final class OwnershipTransferInput
{
    /** @ de quem vai receber a comuna; precisa participar dela. */
    #[Assert\NotBlank(message: 'Escolha quem vai receber a comuna.')]
    #[Assert\Length(max: 40)]
    public string $handle = '';
}
