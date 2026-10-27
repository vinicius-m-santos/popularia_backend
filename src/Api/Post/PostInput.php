<?php

declare(strict_types=1);

namespace App\Api\Post;

use App\Api\Constraint\TextRules;
use App\Entity\Post;
use App\Enum\Topic;
use Symfony\Component\Validator\Constraints as Assert;

final class PostInput
{
    #[Assert\Choice(callback: [Topic::class, 'values'], message: 'Assunto desconhecido.')]
    public ?string $topic = null;

    #[Assert\Uuid(message: 'Documento inválido.')]
    public ?string $documentId = null;

    /** Comuna onde publicar; null publica no próprio perfil. */
    #[Assert\Uuid(message: 'Comuna inválida.')]
    public ?string $comunaId = null;

    #[Assert\When(expression: 'this.documentId == null', constraints: [new Assert\NotBlank(message: 'Escreva alguma coisa para publicar.', normalizer: 'trim')])]
    #[Assert\Length(max: Post::BODY_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public string $body = '';
}
