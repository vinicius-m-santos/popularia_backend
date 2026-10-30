<?php

declare(strict_types=1);

namespace App\Api\Moderation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Content\ReportModeration;
use App\Security\CurrentUser;

/** @implements ProcessorInterface<ReportInput, null> */
final readonly class ReportProcessor implements ProcessorInterface
{
    public function __construct(private CurrentUser $currentUser, private ReportModeration $moderation)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->moderation->submit($this->currentUser->get(), $data->targetType, $data->targetId, $data->reasonCode, $data->description);

        return null;
    }
}
