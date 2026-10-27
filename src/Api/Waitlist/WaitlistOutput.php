<?php

declare(strict_types=1);

namespace App\Api\Waitlist;

final readonly class WaitlistOutput
{
    public function __construct(
        public string $message,
    ) {
    }
}
