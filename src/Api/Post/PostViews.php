<?php

declare(strict_types=1);

namespace App\Api\Post;

use App\Api\Profile\AuthorView;
use App\Content\ComunaModerationPolicy;
use App\Entity\Comuna;
use App\Entity\Poll;
use App\Entity\Post;
use App\Entity\User;
use App\Poll\PollPercents;
use App\Repository\ComunaMembershipRepository;
use App\Repository\PollVoteRepository;

/**
 * Monta PostView com o estado de quem vê. "canComment" e "canRemove" são só dicas para a
 * interface e seguem as mesmas regras de ContentPolicy::whyCannotComment e de
 * ComunaModerationPolicy; a decisão que vale é a da operação, que consulta as políticas de novo.
 */
final readonly class PostViews
{
    public function __construct(
        private ComunaMembershipRepository $memberships,
        private ComunaModerationPolicy $moderation,
        private \App\Library\LibraryViews $library,
        private \App\Library\ObjectStorage $storage,
        private PollVoteRepository $votes,
    ) {
    }

    public function one(Post $post, ?User $viewer): PostView
    {
        return $this->many([$post], $viewer)[0];
    }

    /**
     * @param list<Post> $posts
     *
     * @return list<PostView>
     */
    public function many(array $posts, ?User $viewer): array
    {
        $comunas = array_values(array_filter(array_map(static fn (Post $p) => $p->getComuna(), $posts)));
        $memberOf = null === $viewer ? [] : $this->memberships->findForComunas($viewer, $comunas);

        $documents = array_values(array_filter(array_map(static fn (Post $p) => $p->libraryDocument, $posts)));
        $documentViews = [];
        foreach ($this->library->many($documents, $viewer) as $view) {
            $documentViews[$view->id] = $view;
        }

        $polls = [];
        foreach ($posts as $post) {
            if (null !== $poll = $post->getPoll()) {
                $polls[] = $poll;
            }
        }
        $choices = null === $viewer ? [] : $this->votes->choicesFor($viewer, $polls);

        return array_map(function (Post $post) use ($viewer, $memberOf, $documentViews, $choices): PostView {
            $comuna = $post->getComuna();
            $membership = null === $comuna ? null : ($memberOf[$comuna->getId()->toRfc4122()] ?? null);
            $isAuthor = null !== $viewer && $post->getAuthor()->getId()->equals($viewer->getId());
            $canComment = null !== $viewer
                && $viewer->isEmailVerified()
                && (null === $comuna || (null !== $membership && !$comuna->isArchived()));
            $canRemove = null !== $comuna && $this->moderation->canRemoveContentBy($comuna, $membership, $post->getAuthor());

            return new PostView(
                $post->getId()->toRfc4122(),
                $post->getBody(),
                $post->getCreatedAt(),
                AuthorView::fromUser($post->getAuthor()),
                null === $comuna ? null : self::comunaSummary($comuna),
                $post->getCommentCount(),
                ['isAuthor' => $isAuthor, 'canComment' => $canComment, 'canDelete' => $isAuthor, 'canRemove' => $canRemove],
                $post->getTopic(),
                null === $post->libraryDocument ? null : ($documentViews[$post->libraryDocument->id->toRfc4122()] ?? null),
                null === $post->media ? null : new \App\Api\PostMedia\PostMediaView(
                    $post->media->id->toRfc4122(), $post->media->kind, $post->media->mimeType, $post->media->byteSize,
                    $post->media->width, $post->media->height, $post->media->durationMs,
                    $this->storage->signedUrl($post->media->storageKey, 300, $post->media->mimeType, 'inline'),
                ),
                null === $post->getPoll() ? null : self::poll($post->getPoll(), $choices[$post->getPoll()->getId()->toRfc4122()] ?? null),
            );
        }, $posts);
    }

    private static function poll(Poll $poll, ?string $votedOptionId): PollView
    {
        $options = $poll->getOptions();
        $counts = array_map(static fn ($option) => $option->getVoteCount(), $options);
        $percents = PollPercents::of($counts);
        $views = [];
        $total = 0;
        foreach ($options as $index => $option) {
            $id = $option->getId()->toRfc4122();
            $total += $counts[$index];
            $views[] = new PollOptionView($id, $option->getLabel(), $counts[$index], $percents[$index], $id === $votedOptionId);
        }

        return new PollView($poll->getId()->toRfc4122(), $poll->getQuestion(), $total, $votedOptionId, $views);
    }

    /** @return array{id: string, slug: string, name: string, status: string} */
    private static function comunaSummary(Comuna $comuna): array
    {
        return ['id' => $comuna->getId()->toRfc4122(), 'slug' => $comuna->getSlug(), 'name' => $comuna->getName(), 'status' => $comuna->getStatus()->value];
    }
}
