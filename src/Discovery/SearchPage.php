<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Entity\Comuna;
use App\Entity\ComunaEvent;
use App\Entity\Post;
use App\Entity\User;

final readonly class SearchPage
{
    /**
     * @param list<Comuna>|list<Post>|list<User>|list<ComunaEvent> $items
     */
    public function __construct(public array $items, public ?string $next)
    {
    }
}
