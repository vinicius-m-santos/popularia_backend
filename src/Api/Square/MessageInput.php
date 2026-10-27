<?php

declare(strict_types=1);

namespace App\Api\Square;

use App\Api\Constraint\TextRules;
use App\Entity\SquareMessage;
use Symfony\Component\Validator\Constraints as Assert;

final class MessageInput
{
    #[Assert\Length(max: SquareMessage::BODY_MAX_LENGTH)]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public ?string $body = null;

    #[Assert\Uuid]
    public ?string $stickerId = null;

    #[Assert\Uuid]
    public ?string $replyToId = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-zA-Z0-9_-]{8,64}$/')]
    public string $clientId = '';
}
