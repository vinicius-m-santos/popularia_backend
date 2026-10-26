<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => User::normalizeEmail($email)]);
    }

    public function findOneByHandle(string $handle): ?User
    {
        return $this->findOneBy(['handle' => User::normalizeHandle($handle)]);
    }

    public function findByHandleOrId(string $value): ?User
    {
        return Uuid::isValid($value) ? $this->find(Uuid::fromString($value)) : $this->findOneByHandle($value);
    }

    public function handleExists(string $handle): bool
    {
        return null !== $this->findOneByHandle($handle);
    }

    /**
     * Relê a conta com SELECT ... FOR UPDATE (precisa de transação aberta). Serializa as operações
     * que mudam quantas comunas a pessoa possui: duas criações simultâneas não passam juntas do limite.
     */
    public function lock(User $user): void
    {
        $this->getEntityManager()->refresh($user, LockMode::PESSIMISTIC_WRITE);
    }
}
