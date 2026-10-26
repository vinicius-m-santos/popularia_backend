<?php

declare(strict_types=1);

namespace App\Api\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Auth\AdminAudit;
use App\Repository\EvidenceHoldRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<EvidenceHoldRelease, EvidenceHoldView> */
final readonly class EvidenceHoldReleaseProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private EvidenceHoldRepository $holds,
        private AdminAudit $audit,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EvidenceHoldView
    {
        $id = (string) ($uriVariables['id'] ?? '');
        $hold = Uuid::isValid($id) ? $this->holds->find(Uuid::fromString($id)) : null;
        if (null === $hold) {
            throw ApiProblem::notFound('Preservação não encontrada.');
        }
        if (!$hold->isActive()) {
            throw ApiProblem::conflict('/errors/already-released', 'Esta preservação já foi encerrada.');
        }

        $admin = $this->currentUser->get();
        $reason = trim($data->reason);
        $hold->release($admin, $reason, $this->clock->now());
        $this->em->flush();
        $this->audit->record($admin, 'evidence_hold.released', $hold->getSubject()->getId(), $reason, ['hold_id' => $hold->getId()->toRfc4122()]);

        return EvidenceHoldView::fromHold($hold);
    }
}
