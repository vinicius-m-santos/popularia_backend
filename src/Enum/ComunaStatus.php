<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Estado da comuna. Arquivar é definitivo pelo app (não há "desarquivar"): a comuna e o histórico
 * ficam para leitura, sem novas publicações, comentários ou membros, e deixam de contar no limite
 * de comunas ativas de quem é proprietário. A exclusão definitiva apaga a linha (não é um estado).
 */
enum ComunaStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
