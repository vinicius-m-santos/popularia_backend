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
use App\Entity\UserAuthIdentity;
use App\Enum\SecurityEventType;
use App\Repository\UserAuthIdentityRepository;
use App\Repository\UserRepository;
use App\Security\RateLimitGuard;
use App\Social\ProviderClaims;
use App\Social\ProviderTokenValidatorInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Autentica um usuário via Google ou Apple.
 *
 * Fluxo:
 * 1. Valida o identity token criptograficamente (assinatura + issuer + audience + exp + nonce).
 * 2. Extrai subject (sub) — identificador estável do provider.
 * 3. Busca UserAuthIdentity por (provider, subject).
 *    a. Encontrou: loga o usuário existente.
 *    b. Não encontrou + e-mail verificado bate com conta existente: retorna 409 (linking necessário).
 *    c. Não encontrou: cria novo User + UserAuthIdentity.
 * 4. Verifica se a conta está ativa.
 * 5. Inicia sessão Popularia normal.
 *
 * @implements ProcessorInterface<SocialLogin, SocialTokens>
 */
final readonly class SocialLoginProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'App\Social\GoogleTokenValidator')]
        private ProviderTokenValidatorInterface $googleValidator,
        #[Autowire(service: 'App\Social\AppleTokenValidator')]
        private ProviderTokenValidatorInterface $appleValidator,
        private UserRepository $users,
        private UserAuthIdentityRepository $identities,
        private SessionManager $sessions,
        private SecurityEventRecorder $events,
        private RateLimitGuard $rateLimit,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SocialTokens
    {
        $ip = RequestContext::clientIp($context);
        $this->rateLimit->consume('social_auth', $ip);

        // 1. Validar token criptograficamente.
        try {
            $claims = $this->validateToken($data);
        } catch (\InvalidArgumentException $e) {
            $this->logger->info('Social auth: token inválido', [
                'provider' => $data->provider,
                'reason' => $e->getMessage(),
            ]);
            throw ApiProblem::unauthorized('/errors/invalid-social-token', 'Token do provider inválido ou expirado.');
        }

        // 2. Buscar identidade pelo subject estável do provider.
        $identity = $this->identities->findByProviderSubject($data->provider, $claims->subject);

        if (null !== $identity) {
            return $this->loginExisting($identity->getUser(), $data->deviceLabel, $context);
        }

        // 3. Identidade não encontrada: verificar conflito de e-mail.
        if (null !== $claims->email && $claims->emailVerified) {
            $existingUser = $this->users->findOneByEmail($claims->email);
            if (null !== $existingUser) {
                // Conta existe com o mesmo e-mail verificado.
                // Não linkar automaticamente: pode ser account takeover.
                // O usuário deve logar com e-mail/senha e adicionar o provider em configurações.
                $this->events->record(
                    SecurityEventType::SocialAuthEmailConflict,
                    $existingUser,
                    context: ['provider' => $data->provider],
                );
                throw ApiProblem::conflict('/errors/account-email-conflict', 'Já existe uma conta com este e-mail. Entre com e-mail e senha para vincular o '.$data->provider.'.');
            }
        }

        // 4. Novo usuário: criar conta + identidade.
        return $this->createAndLogin($data, $claims, $context);
    }

    private function validateToken(SocialLogin $data): ProviderClaims
    {
        return match ($data->provider) {
            UserAuthIdentity::PROVIDER_GOOGLE => $this->googleValidator->validate($data->idToken),
            UserAuthIdentity::PROVIDER_APPLE => $this->appleValidator->validate($data->idToken, $data->nonce),
            default => throw new \InvalidArgumentException('Provider desconhecido: '.$data->provider),
        };
    }

    /** @param array<string, mixed> $context */
    private function loginExisting(User $user, ?string $deviceLabel, array $context): SocialTokens
    {
        if (!$user->isActive()) {
            $this->events->record(SecurityEventType::LoginBlockedAccountStatus, $user, context: ['status' => $user->getStatus()->value]);
            throw ApiProblem::accountInactive();
        }

        $pair = $this->sessions->start($user, $this->normalizeDeviceLabel($deviceLabel));
        $this->events->record(SecurityEventType::LoginSucceeded, $user, $pair->session, ['method' => 'social']);

        return SocialTokens::fromPair($pair, $user->isPendingOnboarding());
    }

    /** @param array<string, mixed> $context */
    private function createAndLogin(SocialLogin $data, ProviderClaims $claims, array $context): SocialTokens
    {
        $now = $this->clock->now();

        // Gera handle temporário único derivado do UUID do usuário.
        $tempUuid = \Symfony\Component\Uid\Uuid::v7();
        $tempHandle = 'u'.substr(str_replace('-', '', $tempUuid->toRfc4122()), 0, 11);

        // Nome inicial: do provider (se disponível) ou placeholder.
        $displayName = $this->extractDisplayName($data, $claims);

        // Só usa o e-mail do provider se ele for verificado; caso contrário, endereço placeholder.
        $verifiedEmail = ($claims->emailVerified && null !== $claims->email) ? $claims->email : null;
        $userEmail = $verifiedEmail ?? $data->provider.'.'.substr($claims->subject, 0, 12).'@noreply.popularia.app';

        $user = new User($userEmail, $tempHandle, $displayName, $now);
        $user->markPendingOnboarding($now);

        if (null !== $verifiedEmail) {
            $user->markEmailVerified($now);
        }

        $identity = new UserAuthIdentity($user, $data->provider, $claims->subject, $claims->email, $now);
        $this->em->persist($user);
        $this->em->persist($identity);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Corrida: outra requisição criou a mesma identidade agora.
            if (str_contains($e->getMessage(), 'uniq_auth_identity_provider_subject')) {
                $this->em->clear();
                $found = $this->identities->findByProviderSubject($data->provider, $claims->subject);
                if (null !== $found) {
                    return $this->loginExisting($found->getUser(), $data->deviceLabel, $context);
                }
            }
            throw $e;
        }

        $pair = $this->sessions->start($user, $this->normalizeDeviceLabel($data->deviceLabel));
        $this->events->record(SecurityEventType::AccountRegistered, $user, context: ['method' => 'social', 'provider' => $data->provider]);
        $this->events->record(SecurityEventType::LoginSucceeded, $user, $pair->session, ['method' => 'social']);

        return SocialTokens::fromPair($pair, true);
    }

    private function extractDisplayName(SocialLogin $data, ProviderClaims $claims): string
    {
        // O mobile pode enviar o nome da primeira autorização Apple.
        if (null !== $data->displayName && '' !== trim($data->displayName)) {
            return trim($data->displayName);
        }
        if (null !== $claims->name && '' !== $claims->name) {
            return $claims->name;
        }

        return 'Novo usuário';
    }

    private function normalizeDeviceLabel(?string $label): ?string
    {
        return null !== $label && '' !== trim($label) ? trim($label) : null;
    }
}
