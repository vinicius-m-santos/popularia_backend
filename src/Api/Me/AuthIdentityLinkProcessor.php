<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Auth\SecurityEventRecorder;
use App\Entity\UserAuthIdentity;
use App\Enum\SecurityEventType;
use App\Repository\UserAuthIdentityRepository;
use App\Security\CurrentUser;
use App\Social\ProviderClaims;
use App\Social\ProviderTokenValidatorInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Vincula um provider à conta já autenticada.
 * Este é o caminho seguro de account linking: o usuário JÁ está autenticado.
 *
 * @implements ProcessorInterface<AuthIdentityLink, AuthIdentitiesView>
 */
final readonly class AuthIdentityLinkProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        #[Autowire(service: 'App\Social\GoogleTokenValidator')]
        private ProviderTokenValidatorInterface $googleValidator,
        #[Autowire(service: 'App\Social\AppleTokenValidator')]
        private ProviderTokenValidatorInterface $appleValidator,
        private UserAuthIdentityRepository $identities,
        private SecurityEventRecorder $events,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AuthIdentitiesView
    {
        $user = $this->currentUser->get();

        try {
            $claims = $this->validateToken($data);
        } catch (\InvalidArgumentException $e) {
            $this->logger->info('Auth identity link: token inválido', ['provider' => $data->provider, 'reason' => $e->getMessage()]);
            throw ApiProblem::unauthorized('/errors/invalid-social-token', 'Token do provider inválido ou expirado.');
        }

        // Verificar se este subject já está vinculado a OUTRA conta.
        $existing = $this->identities->findByProviderSubject($data->provider, $claims->subject);
        if (null !== $existing) {
            if (!$existing->getUser()->getId()->equals($user->getId())) {
                // Subject já vinculado a conta diferente: não é possível vincular.
                throw ApiProblem::conflict('/errors/identity-already-linked', 'Esta conta '.$data->provider.' já está vinculada a outro usuário Popularia.');
            }

            // Já vinculado a esta mesma conta: idempotente.
            return AuthIdentitiesView::fromEntities($this->identities->findForUser($user));
        }

        $now = $this->clock->now();
        $identity = new UserAuthIdentity($user, $data->provider, $claims->subject, $claims->email, $now);
        $this->em->persist($identity);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw ApiProblem::conflict('/errors/identity-already-linked', 'Esta identidade já está vinculada.');
        }

        $this->events->record(SecurityEventType::SocialAuthIdentityLinked, $user, context: ['provider' => $data->provider]);

        return AuthIdentitiesView::fromEntities($this->identities->findForUser($user));
    }

    private function validateToken(AuthIdentityLink $data): ProviderClaims
    {
        return match ($data->provider) {
            UserAuthIdentity::PROVIDER_GOOGLE => $this->googleValidator->validate($data->idToken),
            UserAuthIdentity::PROVIDER_APPLE => $this->appleValidator->validate($data->idToken, $data->nonce),
            default => throw new \InvalidArgumentException('Provider desconhecido.'),
        };
    }
}
