<?php

declare(strict_types=1);

namespace App\Api\Event;

/** Resumo para uma publicação que aponta para o evento. Não é um recurso próprio. */
final readonly class EventSummary
{
    public function __construct(
        public string $id,
        public string $title,
        public string $format,
        public string $status,
        public \DateTimeImmutable $startsAt,
        public string $timeZone,
        public ?string $placeName,
        public int $goingCount,
        public int $maybeCount,
        public bool $past,
    ) {
    }
}
