<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserAuthIdentity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UserAuthIdentity> */
class UserAuthIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAuthIdentity::class);
    }

    public function findByProviderSubject(string $provider, string $subject): ?UserAuthIdentity
    {
        return $this->findOneBy(['provider' => $provider, 'providerSubject' => $subject]);
    }

    /**
     * @return list<UserAuthIdentity>
     */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'ASC']);
    }

    public function hasProvider(User $user, string $provider): bool
    {
        return null !== $this->findOneBy(['user' => $user, 'provider' => $provider]);
    }
}
