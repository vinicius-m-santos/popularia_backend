<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Auth\SecurityEventRecorder;
use App\Auth\SessionManager;
use App\Enum\SecurityEventType;
use App\Repository\AuthSessionRepository;
use App\Security\CurrentUser;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<SessionView, null> */
final readonly class SessionRevokeProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private AuthSessionRepository $sessions,
        private SessionManager $sessionManager,
        private SecurityEventRecorder $events,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = $this->currentUser->get();
        $session = $this->sessions->findActiveForUserById($user, Uuid::fromString($data->id)) ?? throw ApiProblem::notFound('Sessão não encontrada.');

        $this->sessionManager->revoke($session, 'revoked_by_owner');
        $this->events->record(SecurityEventType::SessionRevoked, $user, $session, ['reason' => 'revoked_by_owner']);

        return null;
    }
}
