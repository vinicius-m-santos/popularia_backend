<?php

declare(strict_types=1);

namespace App\Api\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\EvidenceHoldRepository;

/** @implements ProviderInterface<EvidenceHoldView> */
final readonly class EvidenceHoldProvider implements ProviderInterface
{
    public function __construct(
        private EvidenceHoldRepository $holds,
    ) {
    }

    /** @return list<EvidenceHoldView> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(EvidenceHoldView::fromHold(...), $this->holds->findLatest(100));
    }
}
