<?php

declare(strict_types=1);

namespace App\Api\Post;

use Symfony\Component\Validator\Constraints as Assert;

/** Troca ou registra o único voto de quem está logado. Repetir a mesma opção não muda o resultado. */
final class PollVoteInput
{
    #[Assert\NotBlank(message: 'Escolha uma opção.')]
    #[Assert\Uuid(message: 'Opção inválida.')]
    public string $optionId = '';
}
