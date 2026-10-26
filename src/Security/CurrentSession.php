<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AuthSession;
use Symfony\Contracts\Service\ResetInterface;

/** Sessão autenticada da requisição atual, preenchida pelo AccessTokenHandler. */
final class CurrentSession implements ResetInterface
{
    private ?AuthSession $session = null;

    public function set(AuthSession $session): void
    {
        $this->session = $session;
    }

    public function get(): ?AuthSession
    {
        return $this->session;
    }

    public function reset(): void
    {
        $this->session = null;
    }
}
