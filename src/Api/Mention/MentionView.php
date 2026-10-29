<?php

declare(strict_types=1);

namespace App\Api\Mention;

use App\Entity\ContentMention;
use App\Media\StoredImageUrl;

/** Dados suficientes para desenhar a menção e abrir o perfil, sem nova consulta por ocorrência. */
final readonly class MentionView
{
    public function __construct(
        public string $userId,
        public string $handle,
        public string $displayName,
        public ?string $avatarUrl,
        public int $start,
        public int $end,
    ) {
    }

    public static function fromMention(ContentMention $mention): self
    {
        $user = $mention->getMentionedUser();

        return new self(
            $user->getId()->toRfc4122(),
            $user->getHandle(),
            $user->getDisplayName(),
            StoredImageUrl::avatar($user),
            $mention->getStartOffset(),
            $mention->getEndOffset(),
        );
    }
}
