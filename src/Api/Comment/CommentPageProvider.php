<?php

declare(strict_types=1);

namespace App\Api\Comment;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Pagination;
use App\Content\ContentPolicy;
use App\Entity\Comment;
use App\Repository\CommentRepository;
use App\Repository\UserBlockRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<CommentPage> */
final readonly class CommentPageProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ContentPolicy $policy,
        private CommentRepository $comments,
        private UserBlockRepository $blocks,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CommentPage
    {
        $viewer = $this->currentUser->find();
        $post = $this->policy->findVisiblePost((string) ($uriVariables['id'] ?? ''), $viewer);

        $page = Pagination::fromContext($context);
        [$comments, $next] = $page->slice($this->comments->findPostPage($post, $page->cursor, $page->limit), static fn (Comment $c) => $c->getId());

        $blocked = $this->blocks->blockedEitherWayIds($viewer);
        $viewerId = $viewer?->getId()->toRfc4122();

        return new CommentPage(
            array_map(static fn (Comment $c) => CommentView::fromComment(
                $c,
                isset($blocked[$c->getAuthor()->getId()->toRfc4122()]) || !$c->getAuthor()->isActive(),
                $viewerId,
            ), $comments),
            $next,
        );
    }
}
