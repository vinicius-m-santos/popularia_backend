<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Component\Clock\ClockInterface;

/** Monta horários no fuso anunciado, com o offset que esse fuso realmente tem. */
trait EventRequests
{
    /** @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    protected function eventBody(string $modify = '+3 days', string $zone = 'America/Manaus', array $override = []): array
    {
        $start = static::getContainer()->get(ClockInterface::class)->now()->modify($modify)->setTimezone(new \DateTimeZone($zone));
        $body = [
            'title' => 'Assembleia mensal',
            'description' => 'Reunião da comuna.',
            'format' => 'in_person',
            'placeName' => 'Sindicato dos Bancários',
            'address' => 'Rua X, 123 — Centro',
            'onlineUrl' => null,
            'timeZone' => $zone,
            'startsAt' => $start->format('Y-m-d\TH:i:sP'),
            'endsAt' => null,
        ];

        return [...$body, ...$override];
    }

    /** @param array<string, mixed> $event
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    protected function eventEdit(array $event, array $override = []): array
    {
        $body = array_intersect_key($event, array_flip(['title', 'description', 'format', 'placeName', 'address', 'onlineUrl', 'timeZone', 'startsAt', 'endsAt']));

        return [...$body, ...$override];
    }
}
