<?php

declare(strict_types=1);

namespace App\Hashtag;

use App\Api\ApiProblem;

/** The same lexical rules are mirrored by the post text renderer. */
final class HashtagParser
{
    public const int MAX_LENGTH = 50;
    public const int MAX_PER_POST = 10;

    /** @return array<string, string> normalized name => first display spelling */
    public function extract(string $text): array
    {
        $urls = [];
        preg_match_all('~\b(?:https?://|www\.)[^\s<>]+~iu', $text, $urls, PREG_OFFSET_CAPTURE);
        $matches = [];
        preg_match_all('/(?<![\p{L}\p{M}\p{N}_#@])#([\p{L}\p{M}\p{N}_]+)/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $result = [];
        $count = 0;
        foreach ($matches[0] as $index => [$whole, $offset]) {
            foreach ($urls[0] as [$url, $urlOffset]) {
                if ($offset >= $urlOffset && $offset < $urlOffset + strlen($url)) {
                    continue 2;
                }
            }
            $name = $matches[1][$index][0];
            if (1 !== preg_match('/[\p{L}\p{N}]/u', $name)) {
                continue;
            }
            ++$count;
            if (mb_strlen($name) > self::MAX_LENGTH) {
                throw ApiProblem::badRequest('/errors/hashtag-length', 'Use até 50 caracteres por hashtag.');
            }
            if ($count > self::MAX_PER_POST) {
                throw ApiProblem::badRequest('/errors/hashtag-limit', 'Use no máximo 10 hashtags por publicação.');
            }
            $key = self::normalize($name);
            $result[$key] ??= $name;
        }

        return $result;
    }

    public static function normalize(string $name): string
    {
        $decomposed = \Normalizer::normalize($name, \Normalizer::FORM_D) ?: $name;
        $withoutAccents = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed;

        return mb_strtolower(\Normalizer::normalize($withoutAccents, \Normalizer::FORM_C) ?: $withoutAccents);
    }

    public static function validSlug(string $slug): bool
    {
        return mb_strlen($slug) <= self::MAX_LENGTH && 1 === preg_match('/^[\p{L}\p{M}\p{N}_]+$/u', $slug) && 1 === preg_match('/[\p{L}\p{N}]/u', $slug) && self::normalize($slug) === $slug;
    }
}
