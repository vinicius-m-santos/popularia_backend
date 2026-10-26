<?php

declare(strict_types=1);

namespace App\Api\Post;

use App\Api\Profile\AuthorView;
use App\Entity\Comuna;
use App\Entity\Post;
use App\Entity\User;
use App\Repository\ComunaMembershipRepository;

/**
 * Monta PostView com o estado de quem vê. "canComment" é só uma dica para a interface e
 * segue a mesma regra de ContentPolicy::whyCannotComment; a decisão que vale é a do
 * CommentCreateProcessor, que consulta a política de novo.
 */
final readonly class PostViews
{
    public function __construct(
        private ComunaMembershipRepository $memberships,
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

        return array_map(function (Post $post) use ($viewer, $memberOf): PostView {
            $comuna = $post->getComuna();
            $isAuthor = null !== $viewer && $post->getAuthor()->getId()->equals($viewer->getId());
            $canComment = null !== $viewer
                && $viewer->isEmailVerified()
                && (null === $comuna || isset($memberOf[$comuna->getId()->toRfc4122()]));

            return new PostView(
                $post->getId()->toRfc4122(),
                $post->getBody(),
                $post->getCreatedAt(),
                AuthorView::fromUser($post->getAuthor()),
                null === $comuna ? null : self::comunaSummary($comuna),
                $post->getCommentCount(),
                ['isAuthor' => $isAuthor, 'canComment' => $canComment, 'canDelete' => $isAuthor],
            );
        }, $posts);
    }

    /** @return array{id: string, slug: string, name: string} */
    private static function comunaSummary(Comuna $comuna): array
    {
        return ['id' => $comuna->getId()->toRfc4122(), 'slug' => $comuna->getSlug(), 'name' => $comuna->getName()];
    }
}
