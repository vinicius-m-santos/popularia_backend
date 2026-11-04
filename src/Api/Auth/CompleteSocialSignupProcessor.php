<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Api\ApiProblem;
use App\Api\RequestContext;
use App\Auth\SecurityEventRecorder;
use App\Auth\SessionManager;
use App\Entity\User;
use App\Entity\UserAuthIdentity;
use App\Enum\SecurityEventType;
use App\Location\LocationCatalog;
use App\Repository\PendingSocialSignupRepository;
use App\Repository\UserAuthIdentityRepository;
use App\Repository\UserRepository;
use App\Security\RateLimitGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/** @implements ProcessorInterface<CompleteSocialSignup, SocialTokens> */
final readonly class CompleteSocialSignupProcessor implements ProcessorInterface
{
    public function __construct(
        private PendingSocialSignupRepository $pendingSignups,
        private UserRepository $users,
        private UserAuthIdentityRepository $identities,
        private LocationCatalog $locations,
        private SessionManager $sessions,
        private SecurityEventRecorder $events,
        private RateLimitGuard $rateLimit,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SocialTokens
    {
        $ip = RequestContext::clientIp($context);
        $tokenHash = hash('sha256', trim($data->pendingSignupToken));
        $this->rateLimit->consume('social_signup_complete', $ip.'|'.$tokenHash);

        $now = $this->clock->now();
        $this->pendingSignups->deleteExpired($now);
        $pending = $this->pendingSignups->findByTokenHash($tokenHash);
        if (null === $pending) {
            throw ApiProblem::badRequest('/errors/pending-signup-expired', 'Este cadastro expirou. Entre novamente com o provedor.');
        }

        if (null !== $pending->getProviderEmail() && null !== $this->users->findOneByEmail($pending->getProviderEmail())) {
            throw ApiProblem::conflict('/errors/account-email-conflict', 'Já existe uma conta com este e-mail. Entre com e-mail e senha para vincular o '.$pending->getProvider().'.');
        }
        if ($this->users->handleExists($data->handle)) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('Esse nome já está em uso.', null, [], $data, 'handle', $data->handle)]));
        }
        if ('BR' === strtoupper((string) ($data->countryCode ?? 'BR')) && (null !== $data->stateIbgeCode || null !== $data->municipalityIbgeCode) && (null === $data->stateIbgeCode || null === $data->municipalityIbgeCode || !$this->locations->hasMunicipality($data->stateIbgeCode, $data->municipalityIbgeCode))) {
            throw ApiProblem::badRequest('/errors/invalid-location', 'Escolha uma cidade válida para o estado selecionado.');
        }

        $providerEmail = $pending->getProviderEmail();
        $email = $providerEmail ?? $pending->getProvider().'.'.substr($pending->getProviderSubject(), 0, 12).'@noreply.popularia.app';
        $user = new User($email, $data->handle, $data->displayName, $now);
        if (null !== $providerEmail && $pending->isEmailVerified()) {
            $user->markEmailVerified($now);
        }
        $user->acceptLegal($now);
        $user->updateProfile(null, $data->bio, $data->city, $data->interests, $now, null, $data->state, $data->country, $data->countryCode, $data->stateIbgeCode, $data->municipalityIbgeCode);
        $identity = new UserAuthIdentity($user, $pending->getProvider(), $pending->getProviderSubject(), $providerEmail, $now);

        $this->em->persist($user);
        $this->em->persist($identity);
        $this->em->remove($pending);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            $this->em->clear();
            if (str_contains($e->getMessage(), 'uniq_auth_identity_provider_subject')) {
                $existingIdentity = $this->identities->findByProviderSubject($pending->getProvider(), $pending->getProviderSubject());
                if (null !== $existingIdentity) {
                    return $this->loginExisting($existingIdentity->getUser(), $data, $context);
                }
            }
            if (str_contains($e->getMessage(), 'uniq_app_user_handle')) {
                throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('Esse nome já está em uso.', null, [], $data, 'handle', $data->handle)]));
            }
            throw $e;
        }

        $pair = $this->sessions->start($user, $this->normalizeDeviceLabel($data->deviceLabel));
        $this->events->record(SecurityEventType::AccountRegistered, $user, context: ['method' => 'social', 'provider' => $pending->getProvider()]);
        $this->events->record(SecurityEventType::LoginSucceeded, $user, $pair->session, ['method' => 'social']);

        return SocialTokens::fromPair($pair, false);
    }

    /** @param array<string, mixed> $context */
    private function loginExisting(User $user, CompleteSocialSignup $data, array $context): SocialTokens
    {
        if (!$user->isActive()) {
            throw ApiProblem::accountInactive();
        }
        if (!$user->hasAcceptedLegal()) {
            throw ApiProblem::forbidden('/errors/legal-acceptance-required', 'Aceite os Termos de Uso e as Regras da Comunidade para entrar.');
        }

        $pair = $this->sessions->start($user, $this->normalizeDeviceLabel($data->deviceLabel));
        $this->events->record(SecurityEventType::LoginSucceeded, $user, $pair->session, ['method' => 'social']);

        return SocialTokens::fromPair($pair, $user->isPendingOnboarding());
    }

    private function normalizeDeviceLabel(?string $label): ?string
    {
        return null !== $label && '' !== trim($label) ? trim($label) : null;
    }
}
