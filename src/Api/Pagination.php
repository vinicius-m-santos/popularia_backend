<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Paginação por cursor: ?cursor=<nextCursor da página anterior>&limit=1..50 (padrão 20).
 * O cursor é o ID (UUID v7) do último item; é opaco para o app, que só o repete.
 */
final readonly class Pagination
{
    public const int DEFAULT_LIMIT = 20;
    public const int MAX_LIMIT = 50;

    private function __construct(
        public ?Uuid $cursor,
        public int $limit,
    ) {
    }

    /** @param array<string, mixed> $context */
    public static function fromContext(array $context): self
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query : null;
        $cursor = (string) $query?->get('cursor', '');
        $limit = (string) $query?->get('limit', '');

        if ('' !== $cursor && !Uuid::isValid($cursor)) {
            throw ApiProblem::badRequest('/errors/invalid-cursor', 'Cursor de paginação inválido.');
        }
        if ('' !== $limit && (!ctype_digit($limit) || (int) $limit < 1 || (int) $limit > self::MAX_LIMIT)) {
            throw ApiProblem::badRequest('/errors/invalid-limit', \sprintf('O limite precisa estar entre 1 e %d.', self::MAX_LIMIT));
        }

        return new self('' === $cursor ? null : Uuid::fromString($cursor), '' === $limit ? self::DEFAULT_LIMIT : (int) $limit);
    }

    /**
     * Corta a lista (consultada com limit + 1) e calcula o próximo cursor.
     *
     * @template T of object
     *
     * @param list<T>           $rows
     * @param callable(T): Uuid $idOf
     *
     * @return array{list<T>, ?string}
     */
    public function slice(array $rows, callable $idOf): array
    {
        $hasMore = \count($rows) > $this->limit;
        $items = \array_slice($rows, 0, $this->limit);
        $last = end($items);

        return [$items, $hasMore && false !== $last ? $idOf($last)->toRfc4122() : null];
    }
}
