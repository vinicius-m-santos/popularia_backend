<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Content\ContentPolicy;
use App\Entity\Post;
use App\Repository\ComunaRepository;
use App\Repository\PostRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<PostPage> */
final readonly class ComunaPostsProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private PostRepository $posts,
        private ContentPolicy $policy,
        private PostViews $views,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PostPage
    {
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $viewer = $this->currentUser->find();
        $this->policy->assertCanReadComuna($comuna, $viewer);

        $page = Pagination::fromContext($context);
        [$posts, $next] = $page->slice($this->posts->findComunaPage($comuna, $viewer, $page->cursor, $page->limit), static fn (Post $p) => $p->getId());

        return new PostPage($this->views->many($posts, $viewer), $next);
    }
}
