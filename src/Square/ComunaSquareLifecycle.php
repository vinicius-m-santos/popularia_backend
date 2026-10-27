<?php

declare(strict_types=1);

namespace App\Square;

use App\Entity\Comuna;
use App\Entity\Square;
use App\Entity\StickerPack;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Clock\ClockInterface;

/** Inclui a Praça na mesma gravação/transação da Comuna, inclusive em fixtures e seeds. */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class ComunaSquareLifecycle
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $em = $event->getObjectManager();
        $uow = $em->getUnitOfWork();
        $insertions = $uow->getScheduledEntityInsertions();
        $squares = [];
        $packs = [];
        foreach ($insertions as $entity) {
            if ($entity instanceof Square && null !== $entity->comuna) {
                $squares[$entity->comuna->getId()->toRfc4122()] = $entity;
            } elseif ($entity instanceof StickerPack && null !== $entity->comuna) {
                $packs[$entity->comuna->getId()->toRfc4122()] = $entity;
            }
        }
        foreach ([...$insertions, ...$uow->getScheduledEntityUpdates()] as $entity) {
            if (!$entity instanceof Comuna) {
                continue;
            }
            $new = isset($insertions[spl_object_id($entity)]);
            $changes = $uow->getEntityChangeSet($entity);
            if (!$new && !isset($changes['status']) && !isset($changes['archivedAt'])) {
                continue;
            }
            $id = $entity->getId()->toRfc4122();
            $square = $squares[$id] ?? $em->getRepository(Square::class)->findOneBy(['comuna' => $entity]);
            if (null === $square) {
                $square = new Square($entity, $entity->getOwner(), $entity->getCreatedAt());
                $square->synchronizeWithComuna($this->clock->now());
                $em->persist($square);
                $uow->computeChangeSet($em->getClassMetadata(Square::class), $square);
            } elseif ($square->synchronizeWithComuna($this->clock->now())) {
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(Square::class), $square);
            }
            if ($new && !isset($packs[$id]) && null === $em->getRepository(StickerPack::class)->findOneBy(['comuna' => $entity])) {
                $pack = new StickerPack($entity, 'comuna', $entity->getName());
                $em->persist($pack);
                $uow->computeChangeSet($em->getClassMetadata(StickerPack::class), $pack);
            }
        }
    }
}
