<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Api\ApiProblem;
use Symfony\Component\Uid\Uuid;

/**
 * Cursor da busca textual: relevância, desempate e id.
 * Listagens sem termo continuam no UUID simples de Pagination.
 */
final readonly class SearchCursor
{
    public function __construct(
        public int $rank,
        public int $tie,
        public Uuid $id,
    ) {
    }

    public static function parse(string $raw): self
    {
        if (1 !== preg_match('/^v1_(\d{1,6})_(\d{1,16})_([0-9a-f-]{36})$/i', $raw, $match) || !Uuid::isValid($match[3])) {
            throw ApiProblem::badRequest('/errors/invalid-cursor', 'Cursor de paginação inválido.');
        }

        return new self((int) $match[1], (int) $match[2], Uuid::fromString($match[3]));
    }

    public function encode(): string
    {
        return 'v1_'.$this->rank.'_'.$this->tie.'_'.$this->id->toRfc4122();
    }
}
