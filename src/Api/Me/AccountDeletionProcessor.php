<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Account\AccountDeletionService;
use App\Api\ApiProblem;
use App\Repository\UserAuthIdentityRepository;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;
use App\Social\AppleAuthorizationRevoker;
use App\Social\ProviderTokenValidatorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** @implements ProcessorInterface<AccountDeletion, null> */
final readonly class AccountDeletionProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private \Symfony\Component\Clock\ClockInterface $clock,
        private UserPasswordHasherInterface $passwordHasher,
        private UserAuthIdentityRepository $identities,
        private RateLimitGuard $rateLimit,
        private AccountDeletionService $deletion,
        private AppleAuthorizationRevoker $appleRevoker,
        #[Autowire(service: 'App\Social\GoogleTokenValidator')] private ProviderTokenValidatorInterface $google,
        #[Autowire(service: 'App\Social\AppleTokenValidator')] private ProviderTokenValidatorInterface $apple,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = $this->currentUser->get();
        $this->rateLimit->consume('account_deletion', $user->getUserIdentifier());
        $claims = null;
        $hasApple = null !== $this->identities->findOneBy(['user' => $user, 'provider' => 'apple']);
        // Mesmo com senha local, a identidade Apple vinculada precisa ser revogada.
        if ($hasApple && ('apple' !== $data->provider || null === $data->idToken)) {
            throw ApiProblem::badRequest('/errors/apple-reauth-required', 'Confirme com Apple para revogar a autorização vinculada.');
        }
        if (null !== $data->provider && null !== $data->idToken) {
            try {
                $claims = ('apple' === $data->provider ? $this->apple : $this->google)->validate($data->idToken, $data->nonce);
            } catch (\InvalidArgumentException) {
                throw ApiProblem::badRequest('/errors/reauthentication-failed', 'Não foi possível confirmar sua identidade.');
            }
            if (null === $claims->issuedAt || $claims->issuedAt < $this->clock->now()->modify('-10 minutes')) {
                throw ApiProblem::badRequest('/errors/reauthentication-expired', 'Confirme novamente com o provedor.');
            }
            $identity = $this->identities->findByProviderSubject($data->provider, $claims->subject);
            if (null === $identity || !$identity->getUser()->getId()->equals($user->getId())) {
                throw ApiProblem::badRequest('/errors/reauthentication-failed', 'Use a identidade vinculada a esta conta.');
            }
        } elseif (!$user->hasPassword() || null === $data->password || !$this->passwordHasher->isPasswordValid($user, $data->password)) {
            throw ApiProblem::badRequest('/errors/reauthentication-failed', 'Confirme sua senha ou uma identidade vinculada.');
        }
        if ($hasApple && null === $claims) {
            throw ApiProblem::badRequest('/errors/apple-reauth-required', 'Confirme com Apple.');
        }
        $this->deletion->delete($user, $hasApple ? fn () => $this->appleRevoker->revokeCode($data->authorizationCode ?? '', $claims->subject) : null);

        return null;
    }
}
