<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use App\Entity\ComunaPinnedPost;
use Symfony\Component\Validator\Constraints as Assert;

/** Nova posição de uma publicação já fixada (1 é a primeira). */
final class PinPositionInput
{
    #[Assert\Range(
        min: 1,
        max: ComunaPinnedPost::MAX_PER_COMUNA,
        notInRangeMessage: 'A posição precisa ser entre {{ min }} e {{ max }}.',
    )]
    public int $position = 0;
}
