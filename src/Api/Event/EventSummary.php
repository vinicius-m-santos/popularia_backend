<?php

declare(strict_types=1);

namespace App\Api\Event;

use App\Api\Profile\AuthorView;

/**
 * Resumo para uma publicação que aponta para o evento. Não é um recurso próprio.
 * `goingPreview` traz até três pessoas que vão, para os rostos do cartão do feed.
 */
final readonly class EventSummary
{
    /**
     * @param list<AuthorView> $goingPreview
     */
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
        public ?string $address = null,
        public ?\DateTimeImmutable $endsAt = null,
        /** "spots" (vagas) ou "goal" (meta mínima); nulo é entrada livre. */
        public ?string $capacityMode = null,
        public ?int $capacity = null,
        public ?string $viewerAttendance = null,
        public array $goingPreview = [],
    ) {
    }
}
