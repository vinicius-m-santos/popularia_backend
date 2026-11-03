<?php

declare(strict_types=1);

namespace App\Enum;

/** Ações de auditoria com nomes estáveis; não troque o valor sem migrar os registros. */
enum AdminAuditAction: string
{
    case UpdatePost = 'UPDATE_POST_ACTION';
    case DeletePost = 'DELETE_POST_ACTION';
    case UpdateEventData = 'UPDATE_EVENT_DATA_ACTION';
}
