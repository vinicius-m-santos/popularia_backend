<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Accepted;
use App\Api\RequestContext;
use App\Auth\AccountCodes;
use App\Auth\SecurityEventRecorder;
use App\Entity\User;
use App\Enum\AccountTokenPurpose;
use App\Enum\SecurityEventType;
use App\Mail\AccountMailer;
use App\Repository\UserRepository;
use App\Security\RateLimitGuard;

/** @implements ProcessorInterface<PasswordResetRequest, Accepted> */
final readonly class PasswordResetRequestProcessor implements ProcessorInterface
{
    public function __construct(
        private UserRepository $users,
        private AccountCodes $codes,
        private AccountMailer $mailer,
        private SecurityEventRecorder $events,
        private RateLimitGuard $rateLimit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Accepted
    {
        $this->rateLimit->consume('password_reset_ip', RequestContext::clientIp($context));

        $user = $this->users->findOneByEmail($data->email);
        if (null === $user) {
            $this->events->record(SecurityEventType::PasswordResetRequested, context: ['account_found' => false]);
        } elseif ($user->isActive() && $this->rateLimit->tryConsume('password_reset_account', User::normalizeEmail($data->email))) {
            // Limite por conta silencioso: a resposta não muda, só deixamos de enviar e-mails.
            $this->mailer->sendPasswordResetCode($user, $this->codes->issue($user, AccountTokenPurpose::PasswordReset));
            $this->events->record(SecurityEventType::PasswordResetRequested, $user);
        }

        return new Accepted('Se houver uma conta com este e-mail, enviamos um código para criar uma nova senha.');
    }
}
