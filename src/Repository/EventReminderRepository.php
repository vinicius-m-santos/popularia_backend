<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComunaEvent;
use App\Entity\EventReminder;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EventReminder> */
class EventReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventReminder::class);
    }

    public function findOne(ComunaEvent $event, User $user): ?EventReminder
    {
        return $this->findOneBy(['event' => $event, 'user' => $user]);
    }

    /** @return list<EventReminder> */
    public function forEvent(ComunaEvent $event): array
    {
        /** @var list<EventReminder> */
        return $this->findBy(['event' => $event]);
    }
}
