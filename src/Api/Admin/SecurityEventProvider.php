<?php

declare(strict_types=1);

namespace App\Api\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Auth\AdminAudit;
use App\Entity\EvidenceHold;
use App\Repository\SecurityEventRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<SecurityEventView> */
final readonly class SecurityEventProvider implements ProviderInterface
{
    private const int LIMIT = 200;

    public function __construct(
        private CurrentUser $currentUser,
        private UserRepository $users,
        private SecurityEventRepository $events,
        private AdminAudit $audit,
    ) {
    }

    /** @return list<SecurityEventView> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $request = $context['request'] ?? null;
        \assert($request instanceof Request);
        $userId = (string) $request->query->get('userId', '');
        $reason = trim((string) $request->query->get('reason', ''));

        if (mb_strlen($reason) < EvidenceHold::REASON_MIN_LENGTH || mb_strlen($reason) > EvidenceHold::REASON_MAX_LENGTH) {
            throw ApiProblem::badRequest('/errors/reason-required', 'Informe o motivo da consulta (mínimo de 10 caracteres).');
        }
        $subject = Uuid::isValid($userId) ? $this->users->find(Uuid::fromString($userId)) : null;
        if (null === $subject) {
            throw ApiProblem::notFound('Conta não encontrada.');
        }

        // A auditoria vem antes da leitura: não existe consulta sem registro.
        $this->audit->record($this->currentUser->get(), 'security_events.viewed', $subject->getId(), $reason);

        return array_map(SecurityEventView::fromEvent(...), $this->events->findRecentForUser($subject, self::LIMIT));
    }
}
