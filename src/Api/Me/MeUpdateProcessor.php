<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** @implements ProcessorInterface<MeUpdate, Me> */
final readonly class MeUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        return $this->em->getConnection()->transactional(function () use ($data): Me {
            $user = $this->currentUser->get();
            $this->em->refresh($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $user->updateProfile($data->displayName, $data->bio, $data->city, $data->interests, $this->clock->now());
            if (null !== $data->isPrivate) {
                $user->setPrivate($data->isPrivate, $this->clock->now());
                if (!$data->isPrivate) {
                    $this->em->getConnection()->executeStatement('UPDATE user_follow SET approved = TRUE WHERE followed_id = ?', [$user->getId()->toRfc4122()]);
                }
            }
            $this->em->flush();

            return Me::fromUser($user);
        });
    }
}
