<?php

declare(strict_types=1);

namespace App\Api\Comment;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ContentPolicy;
use App\Repository\CommentRepository;
use App\Repository\PostRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<object, null> */
final readonly class CommentDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ContentPolicy $policy,
        private CommentRepository $comments,
        private PostRepository $posts,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $actor = $this->currentUser->get();
        $id = (string) ($uriVariables['id'] ?? '');
        $comment = Uuid::isValid($id) ? $this->comments->find(Uuid::fromString($id)) : null;
        if (null === $comment || !$this->policy->canViewPost($comment->getPost(), $actor)) {
            throw ApiProblem::notFound('Comentário não encontrado.');
        }
        if (!$comment->getAuthor()->getId()->equals($actor->getId())) {
            throw ApiProblem::forbidden('/errors/not-the-author', 'Só quem escreveu pode apagar este comentário.');
        }
        if (!$comment->isPublished()) {
            return null;
        }

        $comment->deleteByAuthor();
        $this->posts->decrementCommentCount($comment->getPost());
        $this->em->flush();

        return null;
    }
}
