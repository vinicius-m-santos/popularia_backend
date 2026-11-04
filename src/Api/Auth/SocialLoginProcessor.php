<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Api\RequestContext;
use App\Auth\SecurityEventRecorder;
use App\Auth\SessionManager;
use App\Entity\PendingSocialSignup;
use App\Entity\User;
use App\Entity\UserAuthIdentity;
use App\Enum\SecurityEventType;
use App\Repository\PendingSocialSignupRepository;
use App\Repository\UserAuthIdentityRepository;
use App\Repository\UserRepository;
use App\Security\RateLimitGuard;
use App\Social\ProviderClaims;
use App\Social\ProviderTokenValidatorInterface;
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
 *    c. Não encontrou: cria um cadastro pendente, sem User nem sessão.
 * 4. O app conclui o perfil e o aceite legal em /auth/social/complete.
 *
 * @implements ProcessorInterface<SocialLogin, SocialAuthResult>
 */
final readonly class SocialLoginProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'App\Social\GoogleTokenValidator')]
        private ProviderTokenValidatorInterface $googleValidator,
        #[Autowire(service: 'App\Social\AppleTokenValidator')]
        private ProviderTokenValidatorInterface $appleValidator,
        private UserRepository $users,
        private PendingSocialSignupRepository $pendingSignups,
        private UserAuthIdentityRepository $identities,
        private SessionManager $sessions,
        private SecurityEventRecorder $events,
        private RateLimitGuard $rateLimit,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SocialAuthResult
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
            return SocialAuthResult::authenticated($this->loginExisting($identity->getUser(), $data->deviceLabel, $context));
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

        // 4. Novo usuário: criar cadastro pendente, sem conta nem sessão.
        return $this->createPendingSignup($data, $claims);
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

        // Conta social ainda em onboarding precisa entrar para concluir o perfil e aceitar.
        if (!$user->hasAcceptedLegal() && !$user->isPendingOnboarding()) {
            $this->events->record(SecurityEventType::LoginFailed, $user, context: ['reason' => 'legal_not_accepted', 'method' => 'social']);
            throw ApiProblem::forbidden('/errors/legal-acceptance-required', 'Aceite os Termos de Uso e as Regras da Comunidade para entrar.');
        }

        $pair = $this->sessions->start($user, $this->normalizeDeviceLabel($deviceLabel));
        $this->events->record(SecurityEventType::LoginSucceeded, $user, $pair->session, ['method' => 'social']);

        return SocialTokens::fromPair($pair, $user->isPendingOnboarding());
    }

    private function createPendingSignup(SocialLogin $data, ProviderClaims $claims): SocialAuthResult
    {
        $now = $this->clock->now();
        $this->pendingSignups->deleteExpired($now);

        // Se a pessoa voltou antes de concluir, o token anterior deixa de valer.
        $previous = $this->pendingSignups->findByProviderSubject($data->provider, $claims->subject);
        if (null !== $previous) {
            $this->em->remove($previous);
            $this->em->flush();
        }

        $rawToken = bin2hex(random_bytes(32));
        $providerEmail = ($claims->emailVerified && null !== $claims->email) ? $claims->email : null;
        $displayName = null !== $data->displayName && '' !== trim($data->displayName)
            ? trim($data->displayName)
            : (null !== $claims->name && '' !== trim($claims->name) ? $claims->name : null);

        $pending = new PendingSocialSignup(
            hash('sha256', $rawToken),
            $data->provider,
            $claims->subject,
            $providerEmail,
            $displayName,
            $claims->emailVerified,
            $now,
        );
        $this->em->persist($pending);
        $this->em->flush();

        return SocialAuthResult::pending($pending, $rawToken);
    }

    private function normalizeDeviceLabel(?string $label): ?string
    {
        return null !== $label && '' !== trim($label) ? trim($label) : null;
    }
}
