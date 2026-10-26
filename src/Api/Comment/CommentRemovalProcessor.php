<?php

declare(strict_types=1);

namespace App\Api\Comment;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Api\Post\ContentRemovalInput;
use App\Content\ComunaGovernance;
use App\Content\ContentPolicy;
use App\Content\TextNormalizer;
use App\Repository\CommentRepository;
use App\Security\CurrentUser;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<ContentRemovalInput, null> */
final readonly class CommentRemovalProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private CommentRepository $comments,
        private ContentPolicy $policy,
        private ComunaGovernance $governance,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $actor = $this->currentUser->get();
        $id = (string) ($uriVariables['id'] ?? '');
        $comment = Uuid::isValid($id) ? $this->comments->find(Uuid::fromString($id)) : null;
        // Mesma regra de leitura da conversa: sem acesso à publicação, o comentário "não existe".
        if (null === $comment || !$this->policy->canViewPost($comment->getPost(), $actor)) {
            throw ApiProblem::notFound('Comentário não encontrado.');
        }
        $this->governance->removeComment($comment, $actor, TextNormalizer::normalize($data->reason));

        return null;
    }
}
