<?php

declare(strict_types=1);

namespace App\Api;

/** Resposta 202 genérica: a mesma mensagem exista ou não a conta, para não revelar quem tem cadastro. */
final readonly class Accepted
{
    public function __construct(
        public string $message,
    ) {
    }
}
