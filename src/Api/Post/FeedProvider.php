<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Entity\Post;
use App\Repository\PostRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;

/** @implements ProviderInterface<PostPage> */
final readonly class FeedProvider implements ProviderInterface
{
    private const array TABS = ['para-voce', 'minhas'];

    public function __construct(
        private CurrentUser $currentUser,
        private PostRepository $posts,
        private PostViews $views,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PostPage
    {
        $request = $context['request'] ?? null;
        $tab = $request instanceof Request ? (string) $request->query->get('tab', 'para-voce') : 'para-voce';
        if (!\in_array($tab, self::TABS, true)) {
            throw ApiProblem::badRequest('/errors/invalid-tab', 'Aba do feed desconhecida.');
        }

        $viewer = $this->currentUser->get();
        $page = Pagination::fromContext($context);
        [$posts, $next] = $page->slice($this->posts->findFeedPage($viewer, $tab, $page->cursor, $page->limit), static fn (Post $p) => $p->getId());

        return new PostPage($this->views->many($posts, $viewer), $next);
    }
}
