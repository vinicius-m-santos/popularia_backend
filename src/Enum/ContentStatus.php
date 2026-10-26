<?php

declare(strict_types=1);

namespace App\Enum;

enum ContentStatus: string
{
    case Published = 'published';
    /** Removido pela moderação: some para todos (a decisão fica registrada na etapa de moderação). */
    case Removed = 'removed';
    /** Apagado pela própria autoria. */
    case Deleted = 'deleted';
}
