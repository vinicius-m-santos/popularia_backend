<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Auth\SecurityEventRecorder;
use App\Auth\SessionManager;
use App\Enum\SecurityEventType;
use App\Mail\AccountMailer;
use App\Security\CurrentSession;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** @implements ProcessorInterface<PasswordChange, null> */
final readonly class PasswordChangeProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private CurrentSession $currentSession,
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
        $user = $this->currentUser->get();
        // Mesmo limite do login: um token roubado não serve para testar senhas sem limite.
        $this->rateLimit->consume('login_account', 'password-change|'.$user->getUserIdentifier());

        if (!$this->passwordHasher->isPasswordValid($user, $data->currentPassword)) {
            throw ApiProblem::forbidden('/errors/wrong-password', 'A senha atual não confere.');
        }

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $data->newPassword), $this->clock->now());
        $this->em->flush();
        $count = $this->sessions->revokeAll($user, 'password_changed', $this->currentSession->get());
        $this->events->record(SecurityEventType::PasswordChanged, $user, $this->currentSession->get(), ['other_sessions_revoked' => $count]);
        $this->mailer->sendPasswordChanged($user);

        return null;
    }
}
