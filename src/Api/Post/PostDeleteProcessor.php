<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ComunaPins;
use App\Content\ContentPolicy;
use App\Repository\ContentMentionRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/** @implements ProcessorInterface<PostView, null> */
final readonly class PostDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ContentPolicy $policy,
        private EntityManagerInterface $em,
        private ContentMentionRepository $mentions,
        private ComunaPins $pins,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $viewer = $this->currentUser->get();
        $post = $this->policy->findVisiblePost($data->id, $viewer);
        if (!$this->policy->canDeletePost($post, $viewer)) {
            throw ApiProblem::forbidden('/errors/not-the-author', 'Só quem publicou pode apagar esta publicação.');
        }

        $post->deleteByAuthor();
        // A enquete, as opções e os votos saem junto. A remoção por moderação conserva o registro.
        $post->detachPoll();
        $this->mentions->deleteForPost($post);
        $this->pins->release($post);
        $this->em->flush();

        return null;
    }
}
