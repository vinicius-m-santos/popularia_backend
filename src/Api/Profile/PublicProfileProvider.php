<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Repository\UserBlockRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<PublicProfile> */
final readonly class PublicProfileProvider implements ProviderInterface
{
    public function __construct(
        private UserRepository $users,
        private UserBlockRepository $blocks,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PublicProfile
    {
        $user = $this->users->findOneByHandle((string) ($uriVariables['handle'] ?? ''));
        if (null === $user || !$user->isActive() || $this->blocks->isBlockedEitherWay($this->currentUser->find(), $user)) {
            throw ApiProblem::notFound('Perfil não encontrado.');
        }

        return PublicProfile::fromUser($user);
    }
}
