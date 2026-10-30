<?php

declare(strict_types=1);

namespace App\Api\Moderation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Content\ReportModeration;
use App\Security\CurrentUser;

/** @implements ProviderInterface<ReportItem> */
final readonly class ReportItemProvider implements ProviderInterface
{
    public function __construct(private ReportModeration $moderation, private CurrentUser $currentUser)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReportItem
    {
        return $this->moderation->show($this->currentUser->get(), (string) ($uriVariables['id'] ?? ''));
    }
}
