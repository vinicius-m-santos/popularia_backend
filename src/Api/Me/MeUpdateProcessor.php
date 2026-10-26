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
        $user = $this->currentUser->get();
        $user->updateProfile($data->displayName, $data->bio, $data->city, $data->interests, $this->clock->now());
        $this->em->flush();

        return Me::fromUser($user);
    }
}
