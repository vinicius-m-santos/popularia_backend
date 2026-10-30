<?php

declare(strict_types=1);

namespace App\Api\Hashtag;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Entity\Hashtag;
use App\Hashtag\HashtagParser;
use App\Repository\PostRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/** @implements ProviderInterface<HashtagView> */
final readonly class HashtagViewProvider implements ProviderInterface
{
    public function __construct(private EntityManagerInterface $em, private CurrentUser $currentUser, private PostRepository $posts)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): HashtagView
    {
        $slug = (string) ($uriVariables['slug'] ?? '');
        if (!HashtagParser::validSlug($slug)) {
            throw ApiProblem::notFound('Tópico não encontrado.');
        }
        $hashtag = $this->em->getRepository(Hashtag::class)->findOneBy(['normalizedName' => $slug]);
        if (null === $hashtag || [] === $this->posts->findHashtagPage($hashtag, $this->currentUser->find(), null, 1)) {
            throw ApiProblem::notFound('Tópico não encontrado.');
        }

        return new HashtagView($hashtag->normalizedName, $hashtag->displayName);
    }
}
