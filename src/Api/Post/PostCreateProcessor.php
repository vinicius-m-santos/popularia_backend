<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ContentPolicy;
use App\Content\TextNormalizer;
use App\Entity\Post;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<PostInput, PostView> */
final readonly class PostCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private ContentPolicy $policy,
        private PostViews $views,
        private RateLimitGuard $rateLimit,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PostView
    {
        $author = $this->currentUser->get();
        $comuna = null;
        if (null !== $data->comunaId) {
            $comuna = $this->comunas->find(Uuid::fromString($data->comunaId)) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        }
        $this->policy->assertCanPostIn($comuna, $author);
        $this->rateLimit->consume('content_creation', $author->getUserIdentifier());

        $post = new Post($author, $comuna, TextNormalizer::normalize($data->body), $this->clock->now());
        $this->em->persist($post);
        $this->em->flush();

        return $this->views->one($post, $author);
    }
}
