<?php

declare(strict_types=1);

namespace App\Api\Constraint;

/**
 * Padrões para texto livre. O app não renderiza HTML, mas caracteres de controle
 * (invisíveis, de terminal) não têm uso legítimo e podem confundir moderação e logs.
 */
final class TextRules
{
    /** Uma linha: nenhum caractere de controle. */
    public const string SINGLE_LINE = '/^[^\x00-\x1F\x7F]*$/u';

    /** Várias linhas: só quebras de linha (\n ou \r\n, que o servidor converte para \n). */
    public const string MULTI_LINE = '/^[^\x00-\x09\x0B\x0C\x0E-\x1F\x7F]*$/u';

    public const string MESSAGE = 'O texto contém caracteres inválidos.';
}
