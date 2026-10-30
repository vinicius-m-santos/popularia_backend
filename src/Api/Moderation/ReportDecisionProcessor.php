<?php

declare(strict_types=1);

namespace App\Api\Moderation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Content\ReportModeration;
use App\Security\CurrentUser;

/** @implements ProcessorInterface<ReportDecision, null> */
final readonly class ReportDecisionProcessor implements ProcessorInterface
{
    public function __construct(private ReportModeration $moderation, private CurrentUser $currentUser)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->moderation->decide($this->currentUser->get(), (string) ($uriVariables['id'] ?? ''), $data->action, $data->note);

        return null;
    }
}
