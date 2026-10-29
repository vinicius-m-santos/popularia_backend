<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\UserFollowRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** @implements ProcessorInterface<LegalAcceptance, Me> */
final readonly class LegalAcceptanceProcessor implements ProcessorInterface
{
    public function __construct(private CurrentUser $currentUser, private EntityManagerInterface $em, private ClockInterface $clock, private UserFollowRepository $follows)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        $user = $this->currentUser->get();
        if (!$user->hasAcceptedLegal()) {
            $user->acceptLegal($this->clock->now());
            $this->em->flush();
        }

        $counts = $this->follows->socialCounts($user);

        return Me::fromUser($user, $counts['followers'], $counts['following']);
    }
}
