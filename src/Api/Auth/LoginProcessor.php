<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Api\RequestContext;
use App\Auth\SecurityEventRecorder;
use App\Auth\SessionManager;
use App\Entity\User;
use App\Enum\SecurityEventType;
use App\Repository\UserRepository;
use App\Security\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** @implements ProcessorInterface<Login, Tokens> */
final readonly class LoginProcessor implements ProcessorInterface
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private PasswordHasherFactoryInterface $hasherFactory,
        private SessionManager $sessions,
        private SecurityEventRecorder $events,
        private RateLimitGuard $rateLimit,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Tokens
    {
        $ip = RequestContext::clientIp($context);
        $accountKey = $ip.'|'.User::normalizeEmail($data->email);
        $this->rateLimit->consume('login_ip', $ip);
        $this->rateLimit->consume('login_account', $accountKey);

        $user = $this->users->findOneByEmail($data->email);
        if (null === $user) {
            // Calcula um hash mesmo assim: o tempo de resposta não revela se o e-mail existe.
            $this->hasherFactory->getPasswordHasher(User::class)->hash($data->password);
            $this->events->record(SecurityEventType::LoginFailed, context: ['reason' => 'unknown_account']);
            throw ApiProblem::invalidCredentials();
        }

        if (!$this->passwordHasher->isPasswordValid($user, $data->password)) {
            $this->events->record(SecurityEventType::LoginFailed, $user, context: ['reason' => 'wrong_password']);
            throw ApiProblem::invalidCredentials();
        }

        if (!$user->isActive()) {
            $this->events->record(SecurityEventType::LoginBlockedAccountStatus, $user, context: ['status' => $user->getStatus()->value]);
            throw ApiProblem::accountInactive();
        }

        if ($this->passwordHasher->needsRehash($user)) {
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $data->password), $this->clock->now());
            $this->em->flush();
        }

        $this->rateLimit->reset('login_account', $accountKey);
        $deviceLabel = null !== $data->deviceLabel && '' !== trim($data->deviceLabel) ? trim($data->deviceLabel) : null;
        $pair = $this->sessions->start($user, $deviceLabel);
        $this->events->record(SecurityEventType::LoginSucceeded, $user, $pair->session);

        return Tokens::fromPair($pair);
    }
}
