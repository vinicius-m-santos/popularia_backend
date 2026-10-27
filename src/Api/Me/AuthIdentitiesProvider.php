<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\UserAuthIdentityRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<AuthIdentitiesView> */
final readonly class AuthIdentitiesProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserAuthIdentityRepository $identities,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AuthIdentitiesView
    {
        return AuthIdentitiesView::fromEntities(
            $this->identities->findForUser($this->currentUser->get()),
        );
    }
}
