<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuthSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<AuthSession> */
class AuthSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthSession::class);
    }

    /** Sessão ativa dona deste access token (pelo hash), com a conta já carregada. */
    public function findActiveByAccessTokenHash(string $hash, \DateTimeImmutable $now): ?AuthSession
    {
        /** @var AuthSession|null */
        return $this->createQueryBuilder('s')
            ->addSelect('u')
            ->join('s.user', 'u')
            ->where('s.accessTokenHash = :hash')
            ->andWhere('s.revokedAt IS NULL')
            ->andWhere('s.accessTokenExpiresAt > :now')
            ->setParameter('hash', $hash)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<AuthSession> */
    public function findActiveForUser(User $user): array
    {
        /** @var list<AuthSession> */
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.revokedAt IS NULL')
            ->orderBy('s.lastUsedAt', 'DESC')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    public function findActiveForUserById(User $user, Uuid $id): ?AuthSession
    {
        return $this->findOneBy(['user' => $user, 'id' => $id, 'revokedAt' => null]);
    }

    /** Apaga sessões encerradas ou sem uso há mais de $before (os refresh tokens vão junto, em cascata). */
    public function deleteStale(\DateTimeImmutable $before): int
    {
        /** @var int */
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.revokedAt < :before OR s.lastUsedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
