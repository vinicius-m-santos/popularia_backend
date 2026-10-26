<?php

declare(strict_types=1);

namespace App\Api\Post;

use App\Api\Constraint\TextRules;
use Symfony\Component\Validator\Constraints as Assert;

/** Motivo da remoção pela moderação da comuna. Vai para a auditoria. */
final class ContentRemovalInput
{
    #[Assert\NotBlank(message: 'Informe o motivo da remoção.', normalizer: 'trim')]
    #[Assert\Length(min: 3, max: 500, minMessage: 'Use pelo menos {{ limit }} caracteres.', maxMessage: 'Use até {{ limit }} caracteres.', normalizer: 'trim')]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public string $reason = '';
}
