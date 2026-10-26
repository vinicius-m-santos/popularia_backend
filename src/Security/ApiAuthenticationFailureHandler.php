<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Respostas 401/403 de autenticação no mesmo formato problem+json do resto da API.
 * - Token ausente em rota protegida, inválido ou expirado: 401 /errors/unauthorized
 *   (o app tenta renovar com o refresh token e, se falhar, volta para o login).
 * - Conta suspensa: 403 /errors/account-inactive.
 */
final class ApiAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface, AuthenticationEntryPointInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return ($exception instanceof AccountStatusException ? ApiProblem::accountInactive() : ApiProblem::unauthorized())->toResponse();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ApiProblem::unauthorized()->toResponse();
    }
}
