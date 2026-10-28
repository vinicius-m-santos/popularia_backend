<?php

declare(strict_types=1);

namespace App\Api\Comment;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ContentPolicy;
use App\Entity\Comment;
use App\Repository\CommentRepository;
use App\Repository\CommentSupportRepository;
use App\Repository\UserBlockRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<mixed, CommentView> */
final readonly class CommentSupportProcessor implements ProcessorInterface
{
    public function __construct(private CurrentUser $currentUser, private CommentRepository $comments, private CommentSupportRepository $supports, private ContentPolicy $policy, private UserBlockRepository $blocks, private EntityManagerInterface $em)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommentView
    {
        $actor = $this->currentUser->get();
        $id = (string) ($uriVariables['id'] ?? '');
        /** @var Comment|null $comment */
        $comment = Uuid::isValid($id) ? $this->comments->find(Uuid::fromString($id)) : null;
        if (null === $comment || !$comment->isPublished()) {
            throw ApiProblem::notFound('Comentário não encontrado.');
        }
        $this->policy->findVisiblePost($comment->getPost()->getId()->toRfc4122(), $actor);
        if ($this->blocks->isBlockedEitherWay($actor, $comment->getAuthor())) {
            throw ApiProblem::notFound('Comentário não encontrado.');
        }

        $connection = $this->em->getConnection();
        if ('comment_support' === $operation->getName()) {
            $connection->executeStatement(
                'INSERT INTO comment_support (id, comment_id, user_id) VALUES (:id, :comment, :user) ON CONFLICT (comment_id, user_id) DO NOTHING',
                ['id' => Uuid::v7()->toRfc4122(), 'comment' => $comment->getId()->toRfc4122(), 'user' => $actor->getId()->toRfc4122()],
            );
        }
        if ('comment_unsupport' === $operation->getName()) {
            $connection->executeStatement('DELETE FROM comment_support WHERE comment_id = :comment AND user_id = :user', ['comment' => $comment->getId()->toRfc4122(), 'user' => $actor->getId()->toRfc4122()]);
        }
        $stats = $this->supports->stats([$comment], $actor)[$id] ?? ['count' => 0, 'mine' => false];

        return CommentView::fromComment($comment, false, $actor->getId()->toRfc4122(), false, $stats['count'], $stats['mine']);
    }
}
