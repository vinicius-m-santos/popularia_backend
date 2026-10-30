<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComunaEvent;
use App\Entity\EventAttendance;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\AttendanceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<EventAttendance> */
class EventAttendanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventAttendance::class);
    }

    public function findOne(ComunaEvent $event, User $user): ?EventAttendance
    {
        return $this->findOneBy(['event' => $event, 'user' => $user]);
    }

    /**
     * Contagens de quem está ativo. Com viewer, tira bloqueio nos dois sentidos.
     *
     * @param list<ComunaEvent> $events
     *
     * @return array<string, array{going: int, maybe: int}>
     */
    public function counts(array $events, ?User $viewer): array
    {
        $counts = [];
        foreach ($events as $event) {
            $counts[$event->getId()->toRfc4122()] = ['going' => 0, 'maybe' => 0];
        }
        if ([] === $events) {
            return $counts;
        }
        $qb = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.event) AS eventId, a.status AS status, COUNT(a.id) AS total')
            ->join('a.user', 'u')
            ->where('a.event IN (:events)')
            ->andWhere('u.status = :active')
            ->andWhere('a.status IN (:statuses)')
            ->groupBy('a.event, a.status')
            ->setParameter('events', $events)
            ->setParameter('active', AccountStatus::Active)
            ->setParameter('statuses', [AttendanceStatus::Going, AttendanceStatus::Maybe]);
        if (null !== $viewer) {
            UserBlockRepository::excludeBlocked($qb, 'u', $viewer);
        }
        /** @var list<array{eventId: string, status: AttendanceStatus, total: int|string}> $rows */
        $rows = $qb->getQuery()->getResult();
        foreach ($rows as $row) {
            $id = $row['eventId'];
            if (!isset($counts[$id])) {
                continue;
            }
            if (AttendanceStatus::Going === $row['status']) {
                $counts[$id]['going'] = (int) $row['total'];
            } else {
                $counts[$id]['maybe'] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * @param list<ComunaEvent> $events
     *
     * @return array<string, AttendanceStatus>
     */
    public function viewerStatuses(array $events, ?User $viewer): array
    {
        if (null === $viewer || [] === $events) {
            return [];
        }
        /** @var list<EventAttendance> $rows */
        $rows = $this->createQueryBuilder('a')
            ->where('a.event IN (:events)')
            ->andWhere('a.user = :viewer')
            ->setParameter('events', $events)
            ->setParameter('viewer', $viewer)
            ->getQuery()
            ->getResult();
        $statuses = [];
        foreach ($rows as $row) {
            $statuses[$row->getEvent()->getId()->toRfc4122()] = $row->getStatus();
        }

        return $statuses;
    }

    /** @return list<User> */
    public function interestedUsers(ComunaEvent $event): array
    {
        /** @var list<EventAttendance> $rows */
        $rows = $this->createQueryBuilder('a')
            ->join('a.user', 'u')
            ->addSelect('u')
            ->where('a.event = :event')
            ->andWhere('a.status IN (:statuses)')
            ->andWhere('u.status = :active')
            ->setParameter('event', $event)
            ->setParameter('statuses', [AttendanceStatus::Going, AttendanceStatus::Maybe])
            ->setParameter('active', AccountStatus::Active)
            ->getQuery()
            ->getResult();

        return array_map(static fn (EventAttendance $row): User => $row->getUser(), $rows);
    }

    /** @return list<EventAttendance> */
    public function findAttendeePage(ComunaEvent $event, AttendanceStatus $status, ?User $viewer, ?Uuid $after, int $limit): array
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.user', 'u')
            ->addSelect('u')
            ->where('a.event = :event')
            ->andWhere('a.status = :status')
            ->andWhere('u.status = :active')
            ->setParameter('event', $event)
            ->setParameter('status', $status)
            ->setParameter('active', AccountStatus::Active)
            ->orderBy('a.id', 'ASC')
            ->setMaxResults($limit + 1);
        if (null !== $after) {
            $qb->andWhere('a.id > :after')->setParameter('after', $after, 'uuid');
        }
        if (null !== $viewer) {
            UserBlockRepository::excludeBlocked($qb, 'u', $viewer);
        }

        /** @var list<EventAttendance> */
        return $qb->getQuery()->getResult();
    }
}
