<?php

declare(strict_types=1);

namespace App\Api\Post;

use App\Api\Constraint\TextRules;
use App\Api\Mention\MentionInput;
use App\Entity\ContentMention;
use App\Entity\Post;
use App\Enum\Topic;
use Symfony\Component\Validator\Constraints as Assert;

final class PostInput
{
    #[Assert\Choice(callback: [Topic::class, 'values'], message: 'Assunto desconhecido.')]
    public ?string $topic = null;

    #[Assert\Uuid(message: 'Documento inválido.')]
    public ?string $documentId = null;

    #[Assert\Uuid(message: 'Mídia inválida.')]
    public ?string $mediaId = null;

    /** Comuna onde publicar; null publica no próprio perfil. */
    #[Assert\Uuid(message: 'Comuna inválida.')]
    public ?string $comunaId = null;

    /** Evento da mesma comuna. Não cria o evento nem é obrigatório. */
    #[Assert\Uuid(message: 'Evento inválido.')]
    public ?string $eventId = null;

    #[Assert\Expression(expression: 'this.documentId == null or this.mediaId == null', message: 'Escolha apenas um anexo: PDF, imagem ou vídeo.')]
    #[Assert\When(expression: 'this.documentId == null and this.mediaId == null and this.poll == null', constraints: [new Assert\NotBlank(message: 'Escreva alguma coisa para publicar.', normalizer: 'trim')])]
    #[Assert\Length(max: Post::BODY_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public string $body = '';

    #[Assert\Valid]
    public ?PollInput $poll = null;

    /**
     * Menções escolhidas no editor. Texto com @ sem este vínculo continua texto comum.
     *
     * @var list<MentionInput>
     */
    #[Assert\Valid]
    #[Assert\Count(max: ContentMention::MAX_PER_CONTENT, maxMessage: 'Mencione no máximo {{ limit }} pessoas.')]
    public array $mentions = [];
}
