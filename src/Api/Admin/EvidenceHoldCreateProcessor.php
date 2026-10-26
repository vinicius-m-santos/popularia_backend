<?php

declare(strict_types=1);

namespace App\Api\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Auth\AdminAudit;
use App\Entity\EvidenceHold;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<EvidenceHoldInput, EvidenceHoldView> */
final readonly class EvidenceHoldCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserRepository $users,
        private AdminAudit $audit,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EvidenceHoldView
    {
        $subject = $this->users->find(Uuid::fromString($data->userId)) ?? throw ApiProblem::notFound('Conta não encontrada.');
        $admin = $this->currentUser->get();
        $reason = trim($data->reason);

        $hold = new EvidenceHold($subject, $reason, $admin, $this->clock->now());
        $this->em->persist($hold);
        $this->em->flush();
        $this->audit->record($admin, 'evidence_hold.created', $subject->getId(), $reason, ['hold_id' => $hold->getId()->toRfc4122()]);

        return EvidenceHoldView::fromHold($hold);
    }
}
