<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Post\PostPage;
use App\Api\Post\PostViews;
use App\Content\ComunaPins;
use App\Content\ContentPolicy;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<PostPage> */
final readonly class PinnedPostsProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private ContentPolicy $policy,
        private ComunaPins $pins,
        private PostViews $views,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PostPage
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $viewer = $this->currentUser->find();
        $this->policy->assertCanReadComuna($comuna, $viewer);

        return new PostPage($this->views->many($this->pins->visiblePosts($comuna, $viewer), $viewer), null);
    }
}
