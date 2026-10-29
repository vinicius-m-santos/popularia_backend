<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserFollow;
use App\Enum\AccountStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    public function countApprovedFollowers(User $user): int
    {
        return $this->socialCounts($user)['followers'];
    }

    /**
     * Contagens de relações aprovadas com a outra conta ainda ativa.
     * Uma ida ao banco; os índices de follower/followed cobrem cada subquery.
     *
     * @return array{followers: int, following: int}
     */
    public function socialCounts(User $user): array
    {
        /** @var array{followers: int|string, following: int|string}|false $row */
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            "SELECT
                (SELECT COUNT(*) FROM user_follow f JOIN app_user u ON u.id = f.follower_id WHERE f.followed_id = :id AND f.approved AND u.status = 'active') AS followers,
                (SELECT COUNT(*) FROM user_follow f JOIN app_user u ON u.id = f.followed_id WHERE f.follower_id = :id AND f.approved AND u.status = 'active') AS following",
            ['id' => $user->getId()->toRfc4122()],
        );
        if (false === $row) {
            return ['followers' => 0, 'following' => 0];
        }

        return [
            'followers' => (int) $row['followers'],
            'following' => (int) $row['following'],
        ];
    }

    /**
     * Página de seguidores ou de seguindo. Uma consulta com join do usuário;
     * o bloqueio entra no WHERE para a paginação não pular nem repetir itens.
     *
     * @return list<UserFollow>
     */
    public function findRelationPage(User $subject, User $viewer, bool $followers, ?Uuid $before, int $limit): array
    {
        $qb = $this->createQueryBuilder('f')->addSelect('u');
        if ($followers) {
            $qb->join('f.follower', 'u')->where('f.followed = :subject');
        } else {
            $qb->join('f.followed', 'u')->where('f.follower = :subject');
        }
        $qb->andWhere('f.approved = true')
            ->andWhere('u.status = :active')
            ->setParameter('subject', $subject)
            ->setParameter('active', AccountStatus::Active)
            ->orderBy('f.id', 'DESC')
            ->setMaxResults($limit + 1);
        if (null !== $before) {
            $qb->andWhere('f.id < :before')->setParameter('before', $before, 'uuid');
        }
        UserBlockRepository::excludeBlocked($qb, 'u', $viewer);

        /** @var list<UserFollow> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
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
     * Relação do viewer com várias pessoas, numa consulta só.
     *
     * @param list<User> $people
     *
     * @return array<string, 'following'|'pending'>
     */
    public function relationsTo(User $viewer, array $people): array
    {
        if ([] === $people) {
            return [];
        }
        /** @var list<array{followed: string, approved: bool}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.followed) AS followed', 'f.approved AS approved')
            ->where('f.follower = :viewer AND f.followed IN (:people)')
            ->setParameter('viewer', $viewer)
            ->setParameter('people', $people)
            ->getQuery()
            ->getArrayResult();
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['followed']] = $row['approved'] ? 'following' : 'pending';
        }

        return $map;
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
