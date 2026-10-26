<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\AuthSessionRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Valida o "Authorization: Bearer <token>" de cada requisição: o hash do token precisa
 * pertencer a uma sessão não encerrada e dentro da validade. Encerrar a sessão (logout,
 * troca de senha, reuso de refresh token) invalida o access token na mesma hora.
 * O estado da conta (suspensa ou ativa) é conferido em seguida pelo UserChecker.
 */
final class AccessTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly AuthSessionRepository $sessions,
        private readonly TokenGenerator $tokens,
        private readonly CurrentSession $currentSession,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $session = $this->sessions->findActiveByAccessTokenHash($this->tokens->hashSessionToken($accessToken), $this->clock->now());
        if (null === $session) {
            throw new BadCredentialsException('Invalid access token.');
        }

        $this->currentSession->set($session);
        $user = $session->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
