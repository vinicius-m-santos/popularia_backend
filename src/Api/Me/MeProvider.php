<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Security\CurrentUser;

/** @implements ProviderInterface<Me> */
final readonly class MeProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        return Me::fromUser($this->currentUser->get());
    }
}
