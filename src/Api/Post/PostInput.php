<?php

declare(strict_types=1);

namespace App\Api\Post;

use App\Api\Constraint\TextRules;
use App\Entity\Post;
use Symfony\Component\Validator\Constraints as Assert;

final class PostInput
{
    /** Comuna onde publicar; null publica no próprio perfil. */
    #[Assert\Uuid(message: 'Comuna inválida.')]
    public ?string $comunaId = null;

    #[Assert\NotBlank(message: 'Escreva alguma coisa para publicar.', normalizer: 'trim')]
    #[Assert\Length(max: Post::BODY_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public string $body = '';
}
