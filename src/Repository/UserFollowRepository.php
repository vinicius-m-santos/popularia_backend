<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserFollow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UserFollow> */
class UserFollowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserFollow::class);
    }

    public function findFollow(User $follower, User $followed): ?UserFollow
    {
        return $this->findOneBy(['follower' => $follower, 'followed' => $followed]);
    }

    /**
     * @param list<User> $people
     *
     * @return array<string, true>
     */
    public function followedIds(User $viewer, array $people): array
    {
        if ([] === $people) {
            return [];
        }
        /** @var list<array{followed: string}> $rows */
        $rows = $this->createQueryBuilder('f')->select('IDENTITY(f.followed) AS followed')
            ->where('f.follower = :viewer AND f.followed IN (:people) AND f.approved = true')
            ->setParameter('viewer', $viewer)->setParameter('people', $people)
            ->getQuery()->getArrayResult();

        return array_fill_keys(array_column($rows, 'followed'), true);
    }

    /**
     * Serializes follow changes by this account, without blocking the FK key-share locks
     * of a simultaneous follow in the opposite direction. Requires an open transaction.
     */
    public function lockFollower(User $follower): void
    {
        $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT id FROM app_user WHERE id = ? FOR NO KEY UPDATE',
            [$follower->getId()->toRfc4122()],
        )->fetchOne();
    }
}
