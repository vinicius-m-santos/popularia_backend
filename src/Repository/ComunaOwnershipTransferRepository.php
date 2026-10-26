<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comuna;
use App\Entity\ComunaOwnershipTransfer;
use App\Enum\OwnershipTransferStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ComunaOwnershipTransfer> */
class ComunaOwnershipTransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComunaOwnershipTransfer::class);
    }

    public function findPending(Comuna $comuna): ?ComunaOwnershipTransfer
    {
        return $this->findOneBy(['comuna' => $comuna, 'status' => OwnershipTransferStatus::Pending]);
    }
}
