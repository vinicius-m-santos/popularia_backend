<?php

declare(strict_types=1);

namespace App\Enum;

enum ComunaVisibility: string
{
    /** Qualquer pessoa lê; quem participa publica. */
    case Public = 'public';
    /** Só quem participa lê e publica. A entrada depende de convite/aprovação (etapa futura). */
    case Private = 'private';
}
