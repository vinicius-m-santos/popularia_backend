<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Repository\UserBlockRepository;
use App\Repository\UserFollowRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<PublicProfile> */
final readonly class PublicProfileProvider implements ProviderInterface
{
    public function __construct(
        private UserRepository $users,
        private UserBlockRepository $blocks,
        private CurrentUser $currentUser,
        private UserFollowRepository $follows,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PublicProfile
    {
        $viewer = $this->currentUser->find();
        $user = $this->users->findByHandleOrId((string) ($uriVariables['handle'] ?? ''));
        if (null === $user || !$user->isActive() || $this->blocks->isBlockedEitherWay($viewer, $user)) {
            throw ApiProblem::notFound('Perfil não encontrado.');
        }

        $follow = null !== $viewer && !$viewer->getId()->equals($user->getId()) ? $this->follows->findFollow($viewer, $user) : null;
        $counts = $this->follows->socialCounts($user);

        return PublicProfile::fromUser($user, $counts['followers'], $counts['following'], $follow?->isApproved() ?? false, null !== $follow && !$follow->isApproved());
    }
}
