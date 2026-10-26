<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ApiProblem;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/** A conta autenticada da requisição. Providers e processors de /me usam isto, nunca um ID vindo do cliente. */
final readonly class CurrentUser
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function get(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw ApiProblem::unauthorized();
        }

        return $user;
    }

    public function find(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
