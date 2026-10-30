<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComunaMembership;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Enum\ContentStatus;
use App\Enum\JoinRequestStatus;
use App\Enum\NotificationType;
use App\Enum\OwnershipTransferStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Notification> */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /** @return list<Notification> At most limit + 1; privacy filtering happens before pagination. */
    public function findVisiblePage(User $viewer, ?Uuid $before, int $limit): array
    {
        $qb = $this->visible($viewer)->orderBy('n.id', 'DESC')->setMaxResults($limit + 1);
        if (null !== $before) {
            $qb->andWhere('n.id < :before')->setParameter('before', $before, 'uuid');
        }

        /** @var list<Notification> */
        return $qb->getQuery()->getResult();
    }

    public function findVisible(User $viewer, string $id): ?Notification
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        /** @var Notification|null */
        return $this->visible($viewer)->andWhere('n.id = :id')->setParameter('id', Uuid::fromString($id), 'uuid')->getQuery()->getOneOrNullResult();
    }

    public function countUnread(User $viewer): int
    {
        return (int) $this->visible($viewer)->select('COUNT(n.id)')->andWhere('n.readAt IS NULL')->getQuery()->getSingleScalarResult();
    }

    public function markAllRead(User $viewer, \DateTimeImmutable $now): void
    {
        $visible = $this->visible($viewer)->select('n.id')->andWhere('n.readAt IS NULL');
        $this->getEntityManager()->createQuery('UPDATE App\\Entity\\Notification nr SET nr.readAt = :now WHERE nr.id IN ('.$visible->getDQL().')')
            ->setParameters($visible->getParameters())->setParameter('now', $now)->execute();
    }

    private function visible(User $viewer): QueryBuilder
    {
        $membership = sprintf('SELECT 1 FROM %s nm WHERE nm.comuna = c AND nm.user = :viewer', ComunaMembership::class);
        $moderation = sprintf('SELECT 1 FROM %s modm WHERE modm.comuna = c AND modm.user = :viewer AND modm.role IN (:moderatorRoles)', ComunaMembership::class);
        $qb = $this->createQueryBuilder('n')
            ->addSelect('a', 'p', 'pa', 'cm', 'cp', 'c', 'jr', 'tr', 'sm', 'sq', 'sp', 'spa', 'ev')
            ->leftJoin('n.actor', 'a')->leftJoin('n.post', 'p')->leftJoin('p.author', 'pa')
            ->leftJoin('n.comment', 'cm')->leftJoin('cm.parent', 'cp')->leftJoin('n.comuna', 'c')
            ->leftJoin('cp.author', 'cpa')
            ->leftJoin('n.joinRequest', 'jr')->leftJoin('n.transfer', 'tr')
            ->leftJoin('n.squareMessage', 'sm')->leftJoin('sm.square', 'sq')->leftJoin('sm.replyTo', 'sp')->leftJoin('sp.author', 'spa')
            ->leftJoin('n.event', 'ev')
            ->where('n.recipient = :viewer')
            ->andWhere("(c.id IS NULL OR c.status <> 'suspended')")
            ->andWhere('(sm.id IS NULL OR (sm.status = :published AND sp.status = :published AND spa.status = :active))')
            ->andWhere('(a.id IS NULL OR a.status = :active)')
            ->andWhere('(p.id IS NULL OR (p.status = :published AND pa.status = :active))')
            ->andWhere('(p.id IS NULL OR c.id IS NOT NULL OR pa.isPrivate = false OR pa = :viewer OR EXISTS (SELECT 1 FROM App\\Entity\\UserFollow nf WHERE nf.follower = :viewer AND nf.followed = pa AND nf.approved = true))')
            ->andWhere('(cm.id IS NULL OR (cm.status = :published AND (cp.id IS NULL OR (cp.status = :published AND cpa.status = :active))))')
            // A rejected request may point to the same public metadata/preview already exposed by GET comuna.
            ->andWhere("(c.id IS NULL OR c.visibility = :public OR EXISTS ($membership) OR n.type = :joinRejected)")
            ->andWhere("(n.type <> :joinRequested OR (jr.status = :pendingRequest AND c.archivedAt IS NULL AND EXISTS ($moderation)))")
            ->andWhere('(n.type <> :ownershipProposed OR (tr.status = :pendingTransfer AND tr.toUser = :viewer AND c.owner = tr.fromUser AND c.archivedAt IS NULL))')
            ->setParameter('viewer', $viewer)->setParameter('active', AccountStatus::Active)
            ->setParameter('published', ContentStatus::Published)->setParameter('public', ComunaVisibility::Public)
            ->setParameter('joinRejected', NotificationType::JoinRejected)->setParameter('joinRequested', NotificationType::JoinRequested)
            ->setParameter('pendingRequest', JoinRequestStatus::Pending)->setParameter('pendingTransfer', OwnershipTransferStatus::Pending)
            ->setParameter('ownershipProposed', NotificationType::OwnershipProposed)
            ->setParameter('moderatorRoles', [ComunaRole::Founder, ComunaRole::Moderator]);
        UserBlockRepository::excludeBlocked($qb, 'a', $viewer);
        // The post's author can differ from the replying actor. Both block relationships matter.
        $qb->andWhere(sprintf('NOT EXISTS (SELECT 1 FROM %s pb WHERE (pb.blocker = :viewer AND pb.blocked = pa) OR (pb.blocker = pa AND pb.blocked = :viewer))', \App\Entity\UserBlock::class));
        $qb->andWhere(sprintf('NOT EXISTS (SELECT 1 FROM %s cb WHERE (cb.blocker = :viewer AND cb.blocked = cpa) OR (cb.blocker = cpa AND cb.blocked = :viewer))', \App\Entity\UserBlock::class));

        $qb->andWhere(sprintf('NOT EXISTS (SELECT 1 FROM %s sb WHERE (sb.blocker = :viewer AND sb.blocked = spa) OR (sb.blocker = spa AND sb.blocked = :viewer))', \App\Entity\UserBlock::class));

        return $qb;
    }
}
