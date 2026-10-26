<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Content\ComunaGovernance;
use App\Content\ContentPolicy;
use App\Content\TextNormalizer;
use App\Security\CurrentUser;

/** @implements ProcessorInterface<ContentRemovalInput, null> */
final readonly class PostRemovalProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ContentPolicy $policy,
        private ComunaGovernance $governance,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $actor = $this->currentUser->get();
        $post = $this->policy->findVisiblePost((string) ($uriVariables['id'] ?? ''), $actor);
        $this->governance->removePost($post, $actor, TextNormalizer::normalize($data->reason));

        return null;
    }
}
