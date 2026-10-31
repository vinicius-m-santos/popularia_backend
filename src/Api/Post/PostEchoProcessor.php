<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Content\ContentPolicy;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<mixed, PostView> */
final readonly class PostEchoProcessor implements ProcessorInterface
{
    public function __construct(private CurrentUser $currentUser, private ContentPolicy $policy, private PostViews $views, private EntityManagerInterface $em)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PostView
    {
        $actor = $this->currentUser->get();
        $post = $this->policy->findVisiblePost((string) ($uriVariables['id'] ?? ''), $actor);
        $connection = $this->em->getConnection();
        $connection->transactional(function () use ($connection, $operation, $post, $actor): void {
            if ('post_echo' === $operation->getName()) {
                $connection->executeStatement(
                    'INSERT INTO post_echo (id, post_id, user_id) VALUES (:id, :post, :user) ON CONFLICT (post_id, user_id) DO NOTHING',
                    ['id' => Uuid::v7()->toRfc4122(), 'post' => $post->getId()->toRfc4122(), 'user' => $actor->getId()->toRfc4122()],
                );
            }
            if ('post_unecho' === $operation->getName()) {
                $connection->executeStatement('DELETE FROM post_echo WHERE post_id = :post AND user_id = :user', ['post' => $post->getId()->toRfc4122(), 'user' => $actor->getId()->toRfc4122()]);
            }
        });

        return $this->views->one($post, $actor);
    }
}
