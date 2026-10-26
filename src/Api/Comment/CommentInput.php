<?php

declare(strict_types=1);

namespace App\Api\Comment;

use App\Api\Constraint\TextRules;
use App\Entity\Comment;
use Symfony\Component\Validator\Constraints as Assert;

final class CommentInput
{
    #[Assert\NotBlank(message: 'Escreva um comentário.', normalizer: 'trim')]
    #[Assert\Length(max: Comment::BODY_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public string $body = '';

    /** Comentário respondido; null para comentar direto na publicação. */
    #[Assert\Uuid(message: 'Comentário inválido.')]
    public ?string $parentId = null;
}
