<?php

declare(strict_types=1);

namespace App\Api\Moderation;

/** Autoria do alvo, sem e-mail e sem quem denunciou. */
final readonly class ReportSubject
{
    public function __construct(
        public string $id,
        public string $handle,
        public string $displayName,
    ) {
    }
}
