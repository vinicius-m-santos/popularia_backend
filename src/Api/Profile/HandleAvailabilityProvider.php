<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Constraint\HandleRequirements;
use App\Api\RequestContext;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\RateLimitGuard;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** @implements ProviderInterface<HandleAvailability> */
final readonly class HandleAvailabilityProvider implements ProviderInterface
{
    public function __construct(
        private UserRepository $users,
        private ValidatorInterface $validator,
        private RateLimitGuard $rateLimit,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): HandleAvailability
    {
        $this->rateLimit->consume('handle_check', RequestContext::clientIp($context));
        $handle = User::normalizeHandle((string) ($uriVariables['handle'] ?? ''));
        $valid = 0 === \count($this->validator->validate($handle, new HandleRequirements()));

        return new HandleAvailability($handle, $valid && !$this->users->handleExists($handle));
    }
}
