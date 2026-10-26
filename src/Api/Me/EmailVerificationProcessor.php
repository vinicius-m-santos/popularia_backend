<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Auth\AccountCodes;
use App\Auth\SecurityEventRecorder;
use App\Enum\AccountTokenPurpose;
use App\Enum\SecurityEventType;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** @implements ProcessorInterface<EmailVerification, null> */
final readonly class EmailVerificationProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private AccountCodes $codes,
        private SecurityEventRecorder $events,
        private RateLimitGuard $rateLimit,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = $this->currentUser->get();
        if ($user->isEmailVerified()) {
            return null;
        }

        $this->rateLimit->consume('email_verification', $user->getUserIdentifier());
        if (!$this->codes->consume($user, AccountTokenPurpose::EmailVerification, $data->code)) {
            $this->events->record(SecurityEventType::EmailVerificationFailed, $user);
            throw ApiProblem::invalidCode();
        }

        $user->markEmailVerified($this->clock->now());
        $this->em->flush();
        $this->events->record(SecurityEventType::EmailVerified, $user);

        return null;
    }
}
