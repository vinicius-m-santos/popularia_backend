<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/** Acesso ao Request que o API Platform passa no $context de providers e processors. */
final class RequestContext
{
    /**
     * IP do cliente visto pelo servidor. Só usa X-Forwarded-For de proxies em TRUSTED_PROXIES.
     *
     * @param array<string, mixed> $context
     */
    public static function clientIp(array $context): string
    {
        $request = $context['request'] ?? null;

        return ($request instanceof Request ? $request->getClientIp() : null) ?? 'unknown';
    }
}
