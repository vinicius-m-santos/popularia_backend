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
