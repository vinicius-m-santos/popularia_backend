<?php

declare(strict_types=1);

namespace App\Content;

/** Texto livre guardado de forma previsível: sem espaços nas pontas, em Unicode NFC e com quebras de linha "\n". */
final class TextNormalizer
{
    public static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        return \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
    }
}
