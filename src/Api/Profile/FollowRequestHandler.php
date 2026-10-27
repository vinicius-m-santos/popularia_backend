<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Entity\UserFollow;
use App\Notification\NotificationCreator;
use App\Repository\UserBlockRepository;
use App\Security\CurrentUser;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<FollowRequestPage>
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class FollowRequestHandler implements ProviderInterface, ProcessorInterface
{
    public function __construct(private CurrentUser $user, private EntityManagerInterface $em, private UserBlockRepository $blocks, private NotificationCreator $notifications)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): FollowRequestPage
    {
        $viewer = $this->user->get();
        $page = Pagination::fromContext($context);
        $qb = $this->em->createQueryBuilder()->select('f', 'u')->from(UserFollow::class, 'f')->join('f.follower', 'u')->where("f.followed = :viewer AND f.approved = false AND u.status = 'active'")->setParameter('viewer', $viewer);
        UserBlockRepository::excludeBlocked($qb, 'u', $viewer);
        if (null !== $page->cursor) {
            $qb->andWhere('f.id < :cursor')->setParameter('cursor', $page->cursor, 'uuid');
        }
        /** @var list<UserFollow> $rows */
        $rows = $qb->orderBy('f.id', 'DESC')->setMaxResults($page->limit + 1)->getQuery()->getResult();
        [$rows, $next] = $page->slice($rows, static fn (UserFollow $f) => $f->getId());

        return new FollowRequestPage(array_map(static fn (UserFollow $f) => ['id' => $f->getId()->toRfc4122(), 'name' => $f->getFollower()->getDisplayName(), 'handle' => $f->getFollower()->getHandle()], $rows), $next);
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->em->getConnection()->transactional(function () use ($operation, $uriVariables): void {
            $viewer = $this->user->get();
            $id = (string) ($uriVariables['id'] ?? '');
            $follow = Uuid::isValid($id) ? $this->em->find(UserFollow::class, Uuid::fromString($id)) : null;
            if (null === $follow || !$follow->getFollowed()->getId()->equals($viewer->getId()) || $this->blocks->isBlockedEitherWay($viewer, $follow->getFollower())) {
                throw ApiProblem::notFound();
            }
            $this->em->refresh($follow, LockMode::PESSIMISTIC_WRITE);
            if (!$follow->isApproved()) {
                if ('follow_approve' === $operation->getName()) {
                    $follow->approve();
                    $this->notifications->followed($follow, $follow->getFollower(), $viewer);
                } else {
                    $this->em->remove($follow);
                }
                $this->em->flush();
            }
        });

        return null;
    }
}
