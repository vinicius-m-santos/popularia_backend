<?php

declare(strict_types=1);

namespace App\Api\Post;

use App\Api\Profile\AuthorView;
use App\Content\ComunaModerationPolicy;
use App\Entity\Comuna;
use App\Entity\Post;
use App\Entity\User;
use App\Repository\ComunaMembershipRepository;

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

        return array_map(function (Post $post) use ($viewer, $memberOf, $documentViews): PostView {
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
            );
        }, $posts);
    }

    /** @return array{id: string, slug: string, name: string, status: string} */
    private static function comunaSummary(Comuna $comuna): array
    {
        return ['id' => $comuna->getId()->toRfc4122(), 'slug' => $comuna->getSlug(), 'name' => $comuna->getName(), 'status' => $comuna->getStatus()->value];
    }
}
