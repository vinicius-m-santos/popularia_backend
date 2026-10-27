<?php

declare(strict_types=1);

namespace App\Enum;

enum NotificationType: string
{
    case SquareReply = 'square_reply';
    case Follow = 'follow';
    case Comment = 'comment';
    case Reply = 'reply';
    case JoinRequested = 'join_requested';
    case JoinApproved = 'join_approved';
    case JoinRejected = 'join_rejected';
    case OwnershipProposed = 'ownership_proposed';
    case OwnershipAccepted = 'ownership_accepted';
    case OwnershipDeclined = 'ownership_declined';
    case OwnershipCancelled = 'ownership_cancelled';
}
