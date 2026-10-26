<?php

declare(strict_types=1);

namespace App\Api\Topic;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Enum\Topic;

/** @implements ProviderInterface<TopicView> */
final readonly class TopicProvider implements ProviderInterface
{
    /** @return list<TopicView> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(TopicView::fromTopic(...), Topic::cases());
    }
}
