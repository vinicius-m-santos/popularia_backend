<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Api\Post\PostPage;
use App\Api\Post\PostViews;
use App\Content\ComunaPins;
use App\Content\ContentPolicy;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;

/**
 * @implements ProcessorInterface<mixed, PostPage|null>
 */
final readonly class PinnedPostProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private ContentPolicy $policy,
        private ComunaPins $pins,
        private PostViews $views,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?PostPage
    {
        $actor = $this->currentUser->get();
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $postId = (string) ($uriVariables['postId'] ?? '');
        $name = $operation->getName();

        if ('comuna_unpin_post' === $name) {
            $this->pins->unpin($comuna, $postId, $actor);

            return null;
        }

        if ('comuna_move_pinned_post' === $name) {
            \assert($data instanceof PinPositionInput);
            $this->pins->move($comuna, $postId, $actor, $data->position);
        } else {
            $post = $this->policy->findVisiblePost($postId, $actor);
            $this->pins->pin($comuna, $post, $actor);
        }

        return new PostPage($this->views->many($this->pins->visiblePosts($comuna, $actor), $actor), null);
    }
}
