<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Content\ComunaGovernance;
use App\Entity\Comuna;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<ComunaQuota> */
final readonly class ComunaQuotaProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private ComunaGovernance $governance,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ComunaQuota
    {
        $me = $this->currentUser->get();
        $problem = $this->governance->whyCannotCreate($me);

        return new ComunaQuota(
            $this->comunas->countActiveOwnedBy($me),
            Comuna::MAX_ACTIVE_PER_OWNER,
            Comuna::CREATION_INTERVAL_DAYS,
            null,
            null === $problem,
            $problem?->getType(),
        );
    }
}
