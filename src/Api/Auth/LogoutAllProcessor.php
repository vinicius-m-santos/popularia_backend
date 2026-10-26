<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Auth\SecurityEventRecorder;
use App\Auth\SessionManager;
use App\Enum\SecurityEventType;
use App\Security\CurrentSession;

/** @implements ProcessorInterface<mixed, null> */
final readonly class LogoutAllProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentSession $currentSession,
        private SessionManager $sessions,
        private SecurityEventRecorder $events,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $session = $this->currentSession->get() ?? throw ApiProblem::unauthorized();
        $count = $this->sessions->revokeAll($session->getUser(), 'logout_all');
        $this->events->record(SecurityEventType::AllSessionsRevoked, $session->getUser(), $session, ['reason' => 'logout_all', 'count' => $count]);

        return null;
    }
}
