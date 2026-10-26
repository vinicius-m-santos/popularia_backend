<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Accepted;
use App\Auth\AccountCodes;
use App\Enum\AccountTokenPurpose;
use App\Mail\AccountMailer;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;

/** @implements ProcessorInterface<mixed, Accepted> */
final readonly class EmailVerificationResendProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private AccountCodes $codes,
        private AccountMailer $mailer,
        private RateLimitGuard $rateLimit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Accepted
    {
        $user = $this->currentUser->get();
        if (!$user->isEmailVerified()) {
            $this->rateLimit->consume('email_verification_resend', $user->getUserIdentifier());
            $this->mailer->sendVerificationCode($user, $this->codes->issue($user, AccountTokenPurpose::EmailVerification));
        }

        return new Accepted('Enviamos um novo código para o seu e-mail.');
    }
}
