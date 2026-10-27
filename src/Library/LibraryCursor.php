<?php

declare(strict_types=1);

namespace App\Library;

use App\Api\ApiProblem;
use App\Api\Pagination;
use Symfony\Component\HttpFoundation\Request;

/** Mantém o cursor UUID existente; relevância acrescenta apenas a posição no grupo de título. */
final class LibraryCursor
{
    /** @param array<string, mixed> $context */
    public static function fromContext(array $context): Pagination
    {
        $request = $context['request'] ?? null;
        if ($request instanceof Request) {
            $cursor = $request->query->getString('cursor');
            if (str_starts_with($cursor, 'r')) {
                if (!preg_match('/^r([0-3])_([0-9a-f-]{36})$/i', $cursor, $match)) {
                    throw ApiProblem::badRequest('/errors/invalid-cursor', 'Cursor da biblioteca inválido.');
                }
                $request->attributes->set('library_rank', (int) $match[1]);
                $request->query->set('cursor', $match[2]);
            }
        }

        return Pagination::fromContext($context);
    }
}
