<?php

declare(strict_types=1);

namespace App\Api\Hashtag;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Api\Post\PostPage;
use App\Api\Post\PostViews;
use App\Entity\Hashtag;
use App\Entity\Post;
use App\Hashtag\HashtagParser;
use App\Repository\PostRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/** @implements ProviderInterface<PostPage> */
final readonly class HashtagPostsProvider implements ProviderInterface
{
    public function __construct(private EntityManagerInterface $em, private CurrentUser $currentUser, private PostRepository $posts, private PostViews $views)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PostPage
    {
        $slug = (string) ($uriVariables['slug'] ?? '');
        if (!HashtagParser::validSlug($slug)) {
            throw ApiProblem::notFound('Tópico não encontrado.');
        }
        $hashtag = $this->em->getRepository(Hashtag::class)->findOneBy(['normalizedName' => $slug]);
        if (null === $hashtag) {
            throw ApiProblem::notFound('Tópico não encontrado.');
        }
        $viewer = $this->currentUser->find();
        $page = Pagination::fromContext($context);
        [$posts, $next] = $page->slice($this->posts->findHashtagPage($hashtag, $viewer, $page->cursor, $page->limit), static fn (Post $post) => $post->getId());

        return new PostPage($this->views->many($posts, $viewer), $next);
    }
}
