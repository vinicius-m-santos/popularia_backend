<?php

declare(strict_types=1);

namespace App\Enum;

/** Proposta de transferência de propriedade de uma comuna. Só "pending" está em aberto. */
enum OwnershipTransferStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    /** Retirada por quem propôs, ou invalidada (a pessoa saiu da comuna, a comuna foi arquivada). */
    case Cancelled = 'cancelled';
}
