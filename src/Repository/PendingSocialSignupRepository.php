<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PendingSocialSignup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PendingSocialSignup> */
final class PendingSocialSignupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingSocialSignup::class);
    }

    public function findByTokenHash(string $tokenHash): ?PendingSocialSignup
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function findByProviderSubject(string $provider, string $subject): ?PendingSocialSignup
    {
        return $this->findOneBy(['provider' => $provider, 'providerSubject' => $subject]);
    }

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('p')
            ->delete()
            ->where('p.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
