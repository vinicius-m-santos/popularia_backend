<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/** @implements ProcessorInterface<Registration, Accepted> */
final readonly class RegistrationProcessor implements ProcessorInterface
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private AccountCodes $codes,
        private AccountMailer $mailer,
        private SecurityEventRecorder $events,
        private RateLimitGuard $rateLimit,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Accepted
    {
        $this->rateLimit->consume('register_ip', RequestContext::clientIp($context));

        if ($this->users->handleExists($data->handle)) {
            throw self::handleTaken($data);
        }

        $now = $this->clock->now();
        $user = new User($data->email, $data->handle, $data->displayName, $now);
        // O hash é calculado nos dois caminhos, para o tempo de resposta não revelar se o e-mail existe.
        $passwordHash = $this->passwordHasher->hashPassword($user, $data->password);

        $existing = $this->users->findOneByEmail($data->email);
        if (null !== $existing) {
            $this->mailer->sendAccountAlreadyExists($existing);
            $this->events->record(SecurityEventType::RegistrationWithExistingEmail, $existing);

            return self::accepted();
        }

        $user->updateProfile(null, $data->bio, $data->city, $data->interests, $now);
        $user->setPasswordHash($passwordHash, $now);
        $this->em->persist($user);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Duas requisições simultâneas com o mesmo @ ou e-mail.
            if (str_contains($e->getMessage(), 'uniq_app_user_handle')) {
                throw self::handleTaken($data);
            }

            return self::accepted();
        }

        $this->mailer->sendVerificationCode($user, $this->codes->issue($user, AccountTokenPurpose::EmailVerification));
        $this->events->record(SecurityEventType::AccountRegistered, $user);

        return self::accepted();
    }

    private static function accepted(): Accepted
    {
        return new Accepted('Se o e-mail puder ser usado, enviamos para ele um código de confirmação.');
    }

    private static function handleTaken(Registration $data): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation('Esse nome já está em uso.', null, [], $data, 'handle', $data->handle),
        ]));
    }
}
