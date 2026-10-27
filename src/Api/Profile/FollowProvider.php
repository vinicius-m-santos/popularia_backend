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

/** @implements ProviderInterface<FollowView> */
final readonly class FollowProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserRepository $users,
        private UserFollowRepository $follows,
        private UserBlockRepository $blocks,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): FollowView
    {
        $me = $this->currentUser->get();
        $target = $this->users->findByHandleOrId((string) ($uriVariables['handle'] ?? ''));
        if (null === $target || !$target->isActive() || $this->blocks->isBlockedEitherWay($me, $target)) {
            throw ApiProblem::notFound('Perfil não encontrado.');
        }

        $follow = $this->follows->findFollow($me, $target);

        return new FollowView($follow?->isApproved() ?? false, null !== $follow && !$follow->isApproved());
    }
}
