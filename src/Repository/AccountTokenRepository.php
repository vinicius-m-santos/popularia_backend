<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Enum\AccountTokenPurpose;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AccountToken> */
class AccountTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountToken::class);
    }

    /** Código ainda aberto mais recente (só um fica aberto por finalidade: emitir um novo fecha os anteriores). */
    public function findLatestOpen(User $user, AccountTokenPurpose $purpose): ?AccountToken
    {
        return $this->findOneBy(['user' => $user, 'purpose' => $purpose, 'closedAt' => null], ['createdAt' => 'DESC']);
    }

    public function closeAllOpen(User $user, AccountTokenPurpose $purpose, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('t')
            ->update()
            ->set('t.closedAt', ':now')
            ->where('t.user = :user')
            ->andWhere('t.purpose = :purpose')
            ->andWhere('t.closedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('user', $user)
            ->setParameter('purpose', $purpose)
            ->getQuery()
            ->execute();
    }

    public function deleteExpiredBefore(\DateTimeImmutable $before): int
    {
        /** @var int */
        return $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
