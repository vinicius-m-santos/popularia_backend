<?php

declare(strict_types=1);

namespace App\Enum;

/** Pedido de entrada numa comuna privada. Só "pending" está em aberto; os outros são decisões. */
enum JoinRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    /** Retirado pela própria pessoa. */
    case Cancelled = 'cancelled';
}
