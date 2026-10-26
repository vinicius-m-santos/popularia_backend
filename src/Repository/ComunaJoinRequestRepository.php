<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comuna;
use App\Entity\ComunaJoinRequest;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\JoinRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ComunaJoinRequest> */
class ComunaJoinRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComunaJoinRequest::class);
    }

    public function findPending(Comuna $comuna, ?User $user): ?ComunaJoinRequest
    {
        return null === $user ? null : $this->findOneBy(['comuna' => $comuna, 'user' => $user, 'status' => JoinRequestStatus::Pending]);
    }

    /** Pedido desta comuna pelo ID da URL; null se o ID não for desta comuna. */
    public function findInComuna(Comuna $comuna, string $id): ?ComunaJoinRequest
    {
        return Uuid::isValid($id) ? $this->findOneBy(['id' => Uuid::fromString($id), 'comuna' => $comuna]) : null;
    }

    /**
     * Pedidos em aberto de contas ativas, do mais antigo para o mais novo.
     *
     * @return list<ComunaJoinRequest> até $limit + 1 itens
     */
    public function findPendingPage(Comuna $comuna, ?Uuid $after, int $limit): array
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('u')
            ->join('r.user', 'u')
            ->where('r.comuna = :comuna')
            ->andWhere('r.status = :pending')
            ->andWhere('u.status = :active')
            ->setParameter('comuna', $comuna)
            ->setParameter('pending', JoinRequestStatus::Pending)
            ->setParameter('active', AccountStatus::Active)
            ->orderBy('r.id', 'ASC')
            ->setMaxResults($limit + 1);
        if (null !== $after) {
            $qb->andWhere('r.id > :after')->setParameter('after', $after, 'uuid');
        }

        /** @var list<ComunaJoinRequest> */
        return $qb->getQuery()->getResult();
    }
}
