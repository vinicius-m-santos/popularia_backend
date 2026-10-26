<?php

declare(strict_types=1);

namespace App\Api\Block;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\UserBlock;
use App\Repository\UserBlockRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<BlockView> */
final readonly class BlockProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserBlockRepository $blocks,
    ) {
    }

    /** @return list<BlockView> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(
            static fn (UserBlock $b) => new BlockView($b->getBlocked()->getHandle(), $b->getBlocked()->getDisplayName(), $b->getCreatedAt()),
            $this->blocks->findByBlocker($this->currentUser->get()),
        );
    }
}
