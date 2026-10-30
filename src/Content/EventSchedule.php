<?php

declare(strict_types=1);

namespace App\Content;

use App\Api\ApiProblem;

/**
 * Instantes absolutos mais o fuso em que a comuna anunciou o horário.
 *
 * A API recebe ISO-8601 com offset e um identificador IANA (o fuso do aparelho de quem
 * organiza, não um padrão do produto). O offset precisa ser o daquele fuso naquele
 * instante. O banco guarda o instante (timestamptz). Quem lê formata de novo nesse
 * fuso, então "19:00" continua 19:00 em Manaus mesmo para quem está em Fernando de
 * Noronha. Não há fuso fixo da plataforma.
 */
final class EventSchedule
{
    public const int MAX_SPAN_SECONDS = 14 * 24 * 3600;
    public const int MAX_LEAD_SECONDS = 366 * 2 * 24 * 3600;

    public static function zone(string $timeZone): \DateTimeZone
    {
        if (!in_array($timeZone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw ApiProblem::badRequest('/errors/event-timezone', 'Fuso horário inválido.', 'Envie um identificador IANA, como o fuso do aparelho.');
        }

        return new \DateTimeZone($timeZone);
    }

    public static function instant(string $value, \DateTimeZone $zone): \DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw ApiProblem::badRequest('/errors/event-time', 'Informe data e hora com fuso, no formato ISO-8601.');
        }
        try {
            $instant = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw ApiProblem::badRequest('/errors/event-time', 'Data ou hora inválida.');
        }
        if ($instant->format('P') !== $instant->setTimezone($zone)->format('P')) {
            throw ApiProblem::badRequest('/errors/event-timezone', 'O horário não corresponde ao fuso informado.');
        }

        return $instant;
    }

    /** @return array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable} */
    public static function bounds(string $startsAt, ?string $endsAt, string $timeZone, \DateTimeImmutable $now, bool $allowPast): array
    {
        $zone = self::zone($timeZone);
        $start = self::instant($startsAt, $zone);
        if (!$allowPast && $start <= $now) {
            throw ApiProblem::badRequest('/errors/event-time', 'O evento precisa começar no futuro.');
        }
        if ($start > $now->modify('+'.self::MAX_LEAD_SECONDS.' seconds')) {
            throw ApiProblem::badRequest('/errors/event-time', 'A data está longe demais.');
        }
        $end = null;
        if (null !== $endsAt && '' !== trim($endsAt)) {
            $end = self::instant($endsAt, $zone);
            if ($end <= $start) {
                throw ApiProblem::badRequest('/errors/event-time', 'O fim precisa ser depois do início.');
            }
            if ($end->getTimestamp() - $start->getTimestamp() > self::MAX_SPAN_SECONDS) {
                throw ApiProblem::badRequest('/errors/event-time', 'O evento pode durar no máximo 14 dias.');
            }
        }

        return [$start, $end];
    }

    /**
     * Um lembrete por confirmação. 24h antes quando há folga; 1h antes se o evento
     * foi marcado (ou remarcado) entre 2h e 24h; nada se faltam menos de 2h.
     * Nunca dispara no ato da criação.
     */
    public static function remindAt(\DateTimeImmutable $now, \DateTimeImmutable $startsAt): ?\DateTimeImmutable
    {
        $seconds = $startsAt->getTimestamp() - $now->getTimestamp();
        if ($seconds < 2 * 3600) {
            return null;
        }
        $at = $startsAt->modify($seconds < 24 * 3600 ? '-1 hour' : '-24 hours');

        return $at->getTimestamp() >= $now->getTimestamp() ? $at : null;
    }

    public static function isPast(\DateTimeImmutable $now, \DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): bool
    {
        return ($endsAt ?? $startsAt) < $now;
    }

    public static function previewWhen(\DateTimeImmutable $startsAt, string $timeZone): string
    {
        $local = $startsAt->setTimezone(self::zone($timeZone));
        $months = [1 => 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

        return $local->format('j').' '.$months[(int) $local->format('n')].' · '.$local->format('H:i');
    }
}
