<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Content\ComunaGovernance;
use App\Enum\ComunaVisibility;
use App\Security\CurrentUser;

/** @implements ProcessorInterface<ComunaCreateInput, ComunaView> */
final readonly class ComunaCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaGovernance $governance,
        private ComunaViews $views,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ComunaView
    {
        $owner = $this->currentUser->get();
        $comuna = $this->governance->create(
            $owner,
            $data->name,
            $data->description,
            ComunaVisibility::from($data->visibility),
            $data->place,
            $data->tone,
            $data->topics,
            array_map(static fn (ComunaRuleInput $r) => $r->toArray(), $data->rules),
        );

        return $this->views->detail($comuna, $owner);
    }
}
