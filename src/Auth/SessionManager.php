<?php

declare(strict_types=1);

namespace App\Auth;

use App\Api\ApiProblem;
use App\Entity\AuthSession;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Enum\SecurityEventType;
use App\Repository\AuthSessionRepository;
use App\Repository\RefreshTokenRepository;
use App\Security\TokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Ciclo de vida das sessões: criar no login, renovar com rotação do refresh token,
 * encerrar uma ou todas.
 *
 * Reuso: se um refresh token já girado for apresentado de novo, alguém pode ter copiado o
 * token. A sessão inteira é encerrada (o dono legítimo e o invasor perdem o acesso) e o
 * incidente vai para o registro de segurança.
 */
final class SessionManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthSessionRepository $sessions,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly TokenGenerator $tokens,
        private readonly SecurityEventRecorder $events,
        private readonly ClockInterface $clock,
        private readonly int $accessTokenTtl,
        private readonly int $refreshTokenTtl,
    ) {
    }

    public function start(User $user, ?string $deviceLabel): TokenPair
    {
        $now = $this->clock->now();
        $accessToken = $this->tokens->newSessionToken();
        $session = new AuthSession(
            $user,
            $deviceLabel,
            $this->tokens->hashSessionToken($accessToken),
            $now->modify(\sprintf('+%d seconds', $this->accessTokenTtl)),
            $now,
        );
        $this->em->persist($session);
        $refreshToken = $this->issueRefreshToken($session, $now);
        $this->em->flush();

        return new TokenPair($session, $accessToken, $this->accessTokenTtl, $refreshToken, $this->refreshTokenTtl);
    }

    /** @throws ApiProblem 401 se o token for inválido, expirado, de sessão encerrada ou reutilizado */
    public function refresh(#[\SensitiveParameter] string $refreshToken): TokenPair
    {
        $now = $this->clock->now();
        $token = $this->refreshTokens->findOneByHash($this->tokens->hashSessionToken($refreshToken));
        if (null === $token) {
            throw ApiProblem::invalidRefreshToken();
        }

        $session = $token->getSession();
        $user = $session->getUser();
        if ($session->isRevoked() || $token->isExpired($now)) {
            throw ApiProblem::invalidRefreshToken();
        }

        if ($token->isRotated() || !$this->refreshTokens->markRotated($token, $now)) {
            $this->revoke($session, 'refresh_token_reuse');
            $this->events->record(SecurityEventType::RefreshTokenReuseDetected, $user, $session);
            throw ApiProblem::invalidRefreshToken();
        }

        if (!$user->isActive()) {
            $this->revoke($session, 'account_inactive');
            throw ApiProblem::accountInactive();
        }

        $accessToken = $this->tokens->newSessionToken();
        $session->rotateAccessToken(
            $this->tokens->hashSessionToken($accessToken),
            $now->modify(\sprintf('+%d seconds', $this->accessTokenTtl)),
            $now,
        );
        $newRefreshToken = $this->issueRefreshToken($session, $now);
        $this->em->flush();

        return new TokenPair($session, $accessToken, $this->accessTokenTtl, $newRefreshToken, $this->refreshTokenTtl);
    }

    public function revoke(AuthSession $session, string $reason): void
    {
        $session->revoke($reason, $this->clock->now());
        $this->em->flush();
    }

    /** Encerra todas as sessões ativas da conta, menos $keep (quando informada). Retorna quantas encerrou. */
    public function revokeAll(User $user, string $reason, ?AuthSession $keep = null): int
    {
        $now = $this->clock->now();
        $count = 0;
        foreach ($this->sessions->findActiveForUser($user) as $session) {
            if (null !== $keep && $session->getId()->equals($keep->getId())) {
                continue;
            }
            $session->revoke($reason, $now);
            ++$count;
        }
        $this->em->flush();

        return $count;
    }

    private function issueRefreshToken(AuthSession $session, \DateTimeImmutable $now): string
    {
        $plain = $this->tokens->newSessionToken();
        $this->em->persist(new RefreshToken(
            $session,
            $this->tokens->hashSessionToken($plain),
            $now->modify(\sprintf('+%d seconds', $this->refreshTokenTtl)),
            $now,
        ));

        return $plain;
    }
}
