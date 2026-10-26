<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EvidenceHold;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EvidenceHold> */
class EvidenceHoldRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvidenceHold::class);
    }

    /** @return list<EvidenceHold> */
    public function findLatest(int $limit): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }
}
