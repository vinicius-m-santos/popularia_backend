<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Api\RequestContext;
use App\Auth\AccountCodes;
use App\Auth\SecurityEventRecorder;
use App\Auth\SessionManager;
use App\Enum\AccountTokenPurpose;
use App\Enum\SecurityEventType;
use App\Mail\AccountMailer;
use App\Repository\UserRepository;
use App\Security\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** @implements ProcessorInterface<PasswordResetConfirmation, null> */
final readonly class PasswordResetConfirmationProcessor implements ProcessorInterface
{
    public function __construct(
        private UserRepository $users,
        private AccountCodes $codes,
        private UserPasswordHasherInterface $passwordHasher,
        private SessionManager $sessions,
        private SecurityEventRecorder $events,
        private AccountMailer $mailer,
        private RateLimitGuard $rateLimit,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->rateLimit->consume('password_reset_confirm', RequestContext::clientIp($context));

        $user = $this->users->findOneByEmail($data->email);
        if (null === $user || !$user->isActive() || !$this->codes->consume($user, AccountTokenPurpose::PasswordReset, $data->code)) {
            $this->events->record(SecurityEventType::PasswordResetFailed, $user);
            throw ApiProblem::invalidCode();
        }

        $now = $this->clock->now();
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $data->newPassword), $now);
        // Quem recebeu o código provou que controla o e-mail.
        $user->markEmailVerified($now);
        $this->em->flush();

        $count = $this->sessions->revokeAll($user, 'password_reset');
        $this->events->record(SecurityEventType::PasswordResetCompleted, $user);
        $this->events->record(SecurityEventType::AllSessionsRevoked, $user, context: ['reason' => 'password_reset', 'count' => $count]);
        $this->mailer->sendPasswordChanged($user);

        return null;
    }
}
