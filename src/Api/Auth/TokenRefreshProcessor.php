<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\RequestContext;
use App\Auth\SessionManager;
use App\Security\RateLimitGuard;

/** @implements ProcessorInterface<TokenRefresh, Tokens> */
final readonly class TokenRefreshProcessor implements ProcessorInterface
{
    public function __construct(
        private SessionManager $sessions,
        private RateLimitGuard $rateLimit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Tokens
    {
        $this->rateLimit->consume('token_refresh', RequestContext::clientIp($context));

        return Tokens::fromPair($this->sessions->refresh($data->refreshToken));
    }
}
