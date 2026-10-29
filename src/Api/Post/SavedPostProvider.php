<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Pagination;
use App\Entity\SavedPost;
use App\Repository\SavedPostRepository;
use App\Security\CurrentUser;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<SavedPostPage> */
final readonly class SavedPostProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private SavedPostRepository $saves,
        private PostViews $views,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SavedPostPage
    {
        $viewer = $this->currentUser->get();
        $page = Pagination::fromContext($context);
        $rows = $this->saves->findVisiblePage($viewer, $page->cursor, $page->limit);
        [$rows, $next] = $page->slice($rows, static fn (SavedPost $save): Uuid => $save->id);
        $posts = $this->views->many(array_map(static fn (SavedPost $save) => $save->post, $rows), $viewer);
        $items = [];
        foreach ($rows as $index => $save) {
            $items[] = new SavedPostItem($save->id->toRfc4122(), $save->createdAt, $posts[$index]);
        }

        return new SavedPostPage($items, $next);
    }
}
