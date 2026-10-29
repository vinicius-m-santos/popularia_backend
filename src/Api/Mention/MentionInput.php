<?php

declare(strict_types=1);

namespace App\Api\Mention;

use Symfony\Component\Validator\Constraints as Assert;

/** Referência enviada pelo editor. O servidor confere o intervalo contra o texto normalizado. */
final class MentionInput
{
    #[Assert\NotBlank(message: 'Menção inválida.')]
    #[Assert\Uuid(message: 'Menção inválida.')]
    public string $userId = '';

    #[Assert\PositiveOrZero(message: 'Menção inválida.')]
    public int $start = 0;

    #[Assert\Positive(message: 'Menção inválida.')]
    public int $end = 0;
}
