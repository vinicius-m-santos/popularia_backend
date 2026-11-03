<?php

declare(strict_types=1);

namespace App\Api\Block;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Repository\UserBlockRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<BlockView> */
final readonly class BlockItemProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserRepository $users,
        private UserBlockRepository $blocks,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BlockView
    {
        $me = $this->currentUser->get();
        $target = $this->users->findOneByHandle((string) ($uriVariables['handle'] ?? ''));
        $block = null === $target ? null : $this->blocks->findBlock($me, $target);
        if (null === $block) {
            throw ApiProblem::notFound('Bloqueio não encontrado.');
        }

        return new BlockView($block->getBlocked()->getHandle(), $block->getBlocked()->getDisplayName(), $block->getCreatedAt());
    }
}
