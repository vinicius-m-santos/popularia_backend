<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Entity\Post;
use App\Repository\PostRepository;
use App\Repository\UserBlockRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<PostPage> */
final readonly class ProfilePostsProvider implements ProviderInterface
{
    public function __construct(
        private UserRepository $users,
        private UserBlockRepository $blocks,
        private PostRepository $posts,
        private CurrentUser $currentUser,
        private PostViews $views,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PostPage
    {
        $viewer = $this->currentUser->find();
        $author = $this->users->findByHandleOrId((string) ($uriVariables['handle'] ?? ''));
        if (null === $author || !$author->isActive() || $this->blocks->isBlockedEitherWay($viewer, $author)) {
            throw ApiProblem::notFound('Perfil não encontrado.');
        }
        $page = Pagination::fromContext($context);
        [$posts, $next] = $page->slice($this->posts->findAuthorPage($author, $viewer, $page->cursor, $page->limit), static fn (Post $post) => $post->getId());

        return new PostPage($this->views->many($posts, $viewer), $next);
    }
}
