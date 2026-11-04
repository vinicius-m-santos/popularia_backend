<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\RequestContext;
use App\Auth\HandleGenerator;
use App\Security\RateLimitGuard;

/** @implements ProviderInterface<HandleSuggestion> */
final readonly class HandleSuggestionProvider implements ProviderInterface
{
    public function __construct(
        private HandleGenerator $handles,
        private RateLimitGuard $rateLimit,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): HandleSuggestion
    {
        $this->rateLimit->consume('handle_check', RequestContext::clientIp($context));

        return new HandleSuggestion($this->handles->generate());
    }
}
