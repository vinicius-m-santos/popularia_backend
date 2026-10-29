<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ApiProblem;
use App\Auth\SecurityEventRecorder;
use App\Enum\SecurityEventType;
use Psr\Container\ContainerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Aplica os limites de taxa de config/packages/framework.yaml e responde 429 com Retry-After.
 * As chaves são hasheadas para não guardar e-mails ou IPs em texto na tabela de limites.
 */
final class RateLimitGuard
{
    public function __construct(
        #[AutowireLocator([
            'push_registration' => new Autowire(service: 'limiter.push_registration'),
            'push_revocation' => new Autowire(service: 'limiter.push_revocation'),
            'login_ip' => new Autowire(service: 'limiter.login_ip'),
            'login_account' => new Autowire(service: 'limiter.login_account'),
            'register_ip' => new Autowire(service: 'limiter.register_ip'),
            'email_verification' => new Autowire(service: 'limiter.email_verification'),
            'email_verification_resend' => new Autowire(service: 'limiter.email_verification_resend'),
            'password_reset_ip' => new Autowire(service: 'limiter.password_reset_ip'),
            'password_reset_account' => new Autowire(service: 'limiter.password_reset_account'),
            'password_reset_confirm' => new Autowire(service: 'limiter.password_reset_confirm'),
            'token_refresh' => new Autowire(service: 'limiter.token_refresh'),
            'handle_check' => new Autowire(service: 'limiter.handle_check'),
            'square_message' => new Autowire(service: 'limiter.square_message'),
            'square_interaction' => new Autowire(service: 'limiter.square_interaction'),
            'poll_vote' => new Autowire(service: 'limiter.poll_vote'),
            'library_upload' => new Autowire(service: 'limiter.library_upload'),
            'post_media_upload' => new Autowire(service: 'limiter.post_media_upload'),
            'profile_image' => new Autowire(service: 'limiter.profile_image'),
            'content_creation' => new Autowire(service: 'limiter.content_creation'),
            'comuna_name_check' => new Autowire(service: 'limiter.comuna_name_check'),
            'comuna_join_request' => new Autowire(service: 'limiter.comuna_join_request'),
            'account_deletion' => new Autowire(service: 'limiter.account_deletion'),
            'social_auth' => new Autowire(service: 'limiter.social_auth'),
            'waitlist' => new Autowire(service: 'limiter.waitlist'),
        ])]
        private readonly ContainerInterface $limiters,
        private readonly SecurityEventRecorder $events,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @throws ApiProblem 429 quando o limite estoura */
    public function consume(string $limiter, string $key): void
    {
        if (!$this->tryConsume($limiter, $key, $retryAfter)) {
            throw ApiProblem::tooManyRequests($retryAfter ?? 60);
        }
    }

    /** Versão silenciosa: retorna false quando o limite estourou (para respostas que não podem revelar nada). */
    public function tryConsume(string $limiter, string $key, ?int &$retryAfter = null): bool
    {
        $limit = $this->factory($limiter)->create(hash('sha256', $key))->consume();
        if ($limit->isAccepted()) {
            return true;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());
        $this->events->record(SecurityEventType::RateLimitExceeded, context: ['limiter' => $limiter]);

        return false;
    }

    public function reset(string $limiter, string $key): void
    {
        $this->factory($limiter)->create(hash('sha256', $key))->reset();
    }

    private function factory(string $limiter): RateLimiterFactoryInterface
    {
        /** @var RateLimiterFactoryInterface */
        return $this->limiters->get($limiter);
    }
}
