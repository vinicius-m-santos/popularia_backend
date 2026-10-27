<?php

declare(strict_types=1);

namespace App\Enum;

/** Estado da conta. Só contas ativas autenticam; a suspensão vale no servidor, em toda requisição. */
enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Deleted = 'deleted';
}
