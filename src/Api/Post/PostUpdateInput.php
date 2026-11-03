<?php

declare(strict_types=1);

namespace App\Api\Post;

use App\Api\Constraint\TextRules;
use App\Entity\Post;
use Symfony\Component\Validator\Constraints as Assert;

/** Edição do conteúdo pelo autor. Anexos, enquete e vínculos ficam como estão. */
final class PostUpdateInput
{
    #[Assert\Length(max: Post::BODY_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public string $body = '';

    #[Assert\Length(max: Post::TITLE_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres no título.')]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public ?string $title = null;
}
