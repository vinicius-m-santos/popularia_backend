<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\UserFollowRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<Me> */
final readonly class MeProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserFollowRepository $follows,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        $user = $this->currentUser->get();

        $counts = $this->follows->socialCounts($user);

        return Me::fromUser($user, $counts['followers'], $counts['following']);
    }
}
