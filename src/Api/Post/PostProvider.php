<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Content\ContentPolicy;
use App\Security\CurrentUser;

/** @implements ProviderInterface<PostView> */
final readonly class PostProvider implements ProviderInterface
{
    public function __construct(
        private ContentPolicy $policy,
        private PostViews $views,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PostView
    {
        $viewer = $this->currentUser->find();

        return $this->views->one($this->policy->findVisiblePost((string) ($uriVariables['id'] ?? ''), $viewer), $viewer);
    }
}
