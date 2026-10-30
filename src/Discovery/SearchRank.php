<?php

declare(strict_types=1);

namespace App\Discovery;

/**
 * Relevância textual da busca global. Números inteiros, com folga entre faixas
 * para um sinal pequeno (ts_rank) não ultrapassar o tipo de correspondência.
 *
 * Pessoas e comunas não usam stemming: handle, nome e slug precisam bater literal.
 * Publicações, eventos e documentos usam o dicionário portuguese só como sinal
 * extra, depois da correspondência literal.
 *
 * Pessoas: handle exato 400, prefixo de handle 300, nome exato 220, prefixo de nome 160,
 * trecho no nome ou handle 100, similaridade >= 0.4 (termo com 5+ caracteres) 40–50.
 * Comunas: nome exato 400, prefixo de nome 300, slug exato 250, prefixo de slug 200,
 * trecho no nome 150, descrição 80, similaridade do nome ou slug 40.
 * Publicações: texto exato 1000, prefixo 800, trecho 400, full-text 300, erro leve 200.
 * Dentro da mesma faixa, ts_rank soma no máximo 99. A data só desempata.
 * Eventos: título exato 1000, prefixo 800, trecho no título 500, local 320, comuna 300,
 * descrição 200, similaridade do título 120, mais até 40 de ts_rank.
 * Com a mesma relevância, evento futuro e não cancelado vem antes; entre futuros, o mais próximo.
 */
final class SearchRank
{
    public const int FUZZY_MIN = 5;

    /** @return array{q_raw: string, q_prefix: string, q_contains: string, q_fuzzy: int} */
    public static function params(string $term): array
    {
        $raw = ltrim($term, '@');
        $escaped = strtr($raw, ['!' => '!!', '%' => '!%', '_' => '!_']);

        return [
            'q_raw' => $raw,
            'q_prefix' => $escaped.'%',
            'q_contains' => '%'.$escaped.'%',
            'q_fuzzy' => mb_strlen($raw) >= self::FUZZY_MIN ? 1 : 0,
        ];
    }

    public static function peopleRank(): string
    {
        return <<<'SQL'
            GREATEST(
                CASE WHEN popularia_search_text(x.handle) = popularia_search_text(:q_raw) THEN 400 ELSE 0 END,
                CASE WHEN popularia_search_text(x.handle) LIKE popularia_search_text(:q_prefix) ESCAPE '!' THEN 300 ELSE 0 END,
                CASE WHEN popularia_search_text(x.display_name) = popularia_search_text(:q_raw) THEN 220 ELSE 0 END,
                CASE WHEN popularia_search_text(x.display_name) LIKE popularia_search_text(:q_prefix) ESCAPE '!' THEN 160 ELSE 0 END,
                CASE WHEN popularia_search_text(x.display_name || ' ' || x.handle) LIKE popularia_search_text(:q_contains) ESCAPE '!' THEN 100 ELSE 0 END,
                CASE WHEN :q_fuzzy = 1 AND similarity(popularia_search_text(x.handle), popularia_search_text(:q_raw)) >= 0.4 THEN 50 ELSE 0 END,
                CASE WHEN :q_fuzzy = 1 AND similarity(popularia_search_text(x.display_name), popularia_search_text(:q_raw)) >= 0.4 THEN 40 ELSE 0 END
            )
            SQL;
    }

    public static function peopleMatch(): string
    {
        return <<<'SQL'
            (
                popularia_search_text(x.display_name || ' ' || x.handle) LIKE popularia_search_text(:q_contains) ESCAPE '!'
                OR (:q_fuzzy = 1 AND (
                    popularia_search_text(x.handle) % popularia_search_text(:q_raw)
                    OR popularia_search_text(x.display_name) % popularia_search_text(:q_raw)
                ))
            )
            SQL;
    }

    public static function comunaRank(): string
    {
        return <<<'SQL'
            GREATEST(
                CASE WHEN popularia_search_text(x.name) = popularia_search_text(:q_raw) THEN 400 ELSE 0 END,
                CASE WHEN popularia_search_text(x.name) LIKE popularia_search_text(:q_prefix) ESCAPE '!' THEN 300 ELSE 0 END,
                CASE WHEN popularia_search_text(x.slug) = popularia_search_text(:q_raw) THEN 250 ELSE 0 END,
                CASE WHEN popularia_search_text(x.slug) LIKE popularia_search_text(:q_prefix) ESCAPE '!' THEN 200 ELSE 0 END,
                CASE WHEN popularia_search_text(x.name) LIKE popularia_search_text(:q_contains) ESCAPE '!' THEN 150 ELSE 0 END,
                CASE WHEN popularia_search_text(x.description) LIKE popularia_search_text(:q_contains) ESCAPE '!' THEN 80 ELSE 0 END,
                CASE WHEN :q_fuzzy = 1 AND similarity(popularia_search_text(x.name), popularia_search_text(:q_raw)) >= 0.4 THEN 40 ELSE 0 END,
                CASE WHEN :q_fuzzy = 1 AND similarity(popularia_search_text(x.slug), popularia_search_text(:q_raw)) >= 0.4 THEN 40 ELSE 0 END
            )
            SQL;
    }

    public static function comunaMatch(): string
    {
        return <<<'SQL'
            (
                popularia_search_text(x.name || ' ' || x.description) LIKE popularia_search_text(:q_contains) ESCAPE '!'
                OR popularia_search_text(x.slug) LIKE popularia_search_text(:q_contains) ESCAPE '!'
                OR (:q_fuzzy = 1 AND (
                    popularia_search_text(x.name) % popularia_search_text(:q_raw)
                    OR popularia_search_text(x.slug) % popularia_search_text(:q_raw)
                ))
            )
            SQL;
    }

    public static function postRank(): string
    {
        return <<<'SQL'
            (
                CASE
                    WHEN popularia_search_text(x.body) = popularia_search_text(:q_raw) THEN 1000
                    WHEN popularia_search_text(x.body) LIKE popularia_search_text(:q_prefix) ESCAPE '!' THEN 800
                    WHEN popularia_search_text(x.body) LIKE popularia_search_text(:q_contains) ESCAPE '!' THEN 400
                    WHEN to_tsvector('portuguese', popularia_search_text(x.body)) @@ websearch_to_tsquery('portuguese', popularia_search_text(:q_raw)) THEN 300
                    WHEN :q_fuzzy = 1 AND word_similarity(popularia_search_text(:q_raw), popularia_search_text(x.body)) >= 0.45 THEN 200
                    ELSE 0
                END
                + CASE
                    WHEN popularia_search_text(x.body) LIKE popularia_search_text(:q_contains) ESCAPE '!'
                        OR to_tsvector('portuguese', popularia_search_text(x.body)) @@ websearch_to_tsquery('portuguese', popularia_search_text(:q_raw))
                    THEN LEAST(99, FLOOR(ts_rank(to_tsvector('portuguese', popularia_search_text(x.body)), websearch_to_tsquery('portuguese', popularia_search_text(:q_raw))) * 100)::int)
                    ELSE 0
                END
            )
            SQL;
    }

    public static function postMatch(): string
    {
        return <<<'SQL'
            (
                popularia_search_text(x.body) LIKE popularia_search_text(:q_contains) ESCAPE '!'
                OR to_tsvector('portuguese', popularia_search_text(x.body)) @@ websearch_to_tsquery('portuguese', popularia_search_text(:q_raw))
                OR (:q_fuzzy = 1 AND word_similarity(popularia_search_text(:q_raw), popularia_search_text(x.body)) >= 0.45)
            )
            SQL;
    }

    public static function eventRank(): string
    {
        return <<<'SQL'
            (
                GREATEST(
                    CASE WHEN popularia_search_text(x.title) = popularia_search_text(:q_raw) THEN 1000 ELSE 0 END,
                    CASE WHEN popularia_search_text(x.title) LIKE popularia_search_text(:q_prefix) ESCAPE '!' THEN 800 ELSE 0 END,
                    CASE WHEN popularia_search_text(x.title) LIKE popularia_search_text(:q_contains) ESCAPE '!' THEN 500 ELSE 0 END,
                    CASE WHEN popularia_search_text(COALESCE(x.place_name, '')) LIKE popularia_search_text(:q_contains) ESCAPE '!' THEN 320 ELSE 0 END,
                    CASE WHEN popularia_search_text(c.name) LIKE popularia_search_text(:q_contains) ESCAPE '!' THEN 300 ELSE 0 END,
                    CASE WHEN popularia_search_text(x.description) LIKE popularia_search_text(:q_contains) ESCAPE '!' THEN 200 ELSE 0 END,
                    CASE WHEN :q_fuzzy = 1 AND similarity(popularia_search_text(x.title), popularia_search_text(:q_raw)) >= 0.4 THEN 120 ELSE 0 END
                )
                + CASE
                    WHEN popularia_search_text(x.title || ' ' || x.description) LIKE popularia_search_text(:q_contains) ESCAPE '!'
                        OR to_tsvector('portuguese', popularia_search_text(x.title || ' ' || x.description)) @@ websearch_to_tsquery('portuguese', popularia_search_text(:q_raw))
                    THEN LEAST(40, FLOOR(ts_rank(to_tsvector('portuguese', popularia_search_text(x.title || ' ' || x.description)), websearch_to_tsquery('portuguese', popularia_search_text(:q_raw))) * 40)::int)
                    ELSE 0
                END
            )
            SQL;
    }

    public static function eventMatch(): string
    {
        return <<<'SQL'
            (
                popularia_search_text(x.title || ' ' || x.description || ' ' || COALESCE(x.place_name, '')) LIKE popularia_search_text(:q_contains) ESCAPE '!'
                OR popularia_search_text(c.name) LIKE popularia_search_text(:q_contains) ESCAPE '!'
                OR to_tsvector('portuguese', popularia_search_text(x.title || ' ' || x.description)) @@ websearch_to_tsquery('portuguese', popularia_search_text(:q_raw))
                OR (:q_fuzzy = 1 AND similarity(popularia_search_text(x.title), popularia_search_text(:q_raw)) >= 0.4)
            )
            SQL;
    }

    public static function eventTie(): string
    {
        return <<<'SQL'
            CASE
                WHEN x.status = 'scheduled' AND x.starts_at >= :now
                THEN (1000000000000000 - (EXTRACT(EPOCH FROM x.starts_at) * 1000)::bigint)
                ELSE (EXTRACT(EPOCH FROM x.starts_at) * 1000)::bigint
            END
            SQL;
    }
}
