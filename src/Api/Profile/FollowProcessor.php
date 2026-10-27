<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Entity\UserFollow;
use App\Notification\NotificationCreator;
use App\Repository\UserBlockRepository;
use App\Repository\UserFollowRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** @implements ProcessorInterface<mixed, FollowView|null> */
final readonly class FollowProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserRepository $users,
        private UserFollowRepository $follows,
        private UserBlockRepository $blocks,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private NotificationCreator $notifications,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?FollowView
    {
        $me = $this->currentUser->get();
        $target = $this->users->findByHandleOrId((string) ($uriVariables['handle'] ?? ''));
        if (null === $target || !$target->isActive() || $this->blocks->isBlockedEitherWay($me, $target)) {
            throw ApiProblem::notFound('Perfil não encontrado.');
        }
        if ($me->getId()->equals($target->getId())) {
            throw ApiProblem::badRequest('/errors/cannot-follow-self', 'Não é possível seguir a própria conta.');
        }

        return $this->em->getConnection()->transactional(function () use ($me, $target, $operation): ?FollowView {
            // Serializes repeat taps/requests before deciding whether an actual new follow exists.
            $ids = [$me->getId()->toRfc4122(), $target->getId()->toRfc4122()];
            sort($ids);
            $this->em->getConnection()->executeQuery('SELECT id FROM app_user WHERE id IN (?, ?) ORDER BY id FOR NO KEY UPDATE', $ids)->fetchAllAssociative();
            $this->em->refresh($target);
            $follow = $this->follows->findFollow($me, $target);
            if ($operation instanceof Delete) {
                if (null !== $follow) {
                    $this->em->remove($follow);
                }
                $this->em->flush();

                return null;
            }
            if (null === $follow) {
                $follow = new UserFollow($me, $target, $this->clock->now(), !$target->isPrivate());
                $this->em->persist($follow);
                if ($follow->isApproved()) {
                    $this->notifications->followed($follow, $me, $target);
                }
                $this->em->flush();
            }

            return new FollowView($follow->isApproved(), !$follow->isApproved());
        });
    }
}
