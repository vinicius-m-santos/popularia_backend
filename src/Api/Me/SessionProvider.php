<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Repository\AuthSessionRepository;
use App\Security\CurrentSession;
use App\Security\CurrentUser;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<SessionView> */
final readonly class SessionProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private CurrentSession $currentSession,
        private AuthSessionRepository $sessions,
    ) {
    }

    /** @return SessionView|list<SessionView> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SessionView|array
    {
        $user = $this->currentUser->get();
        $current = $this->currentSession->get();

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(
                static fn ($session) => SessionView::fromSession($session, $current),
                $this->sessions->findActiveForUser($user),
            );
        }

        $id = (string) ($uriVariables['id'] ?? '');
        // Busca sempre restrita à conta autenticada: o ID de uma sessão alheia dá 404.
        $session = Uuid::isValid($id) ? $this->sessions->findActiveForUserById($user, Uuid::fromString($id)) : null;
        if (null === $session) {
            throw ApiProblem::notFound('Sessão não encontrada.');
        }

        return SessionView::fromSession($session, $current);
    }
}
