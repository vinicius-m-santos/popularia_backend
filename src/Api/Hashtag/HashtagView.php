<?php

declare(strict_types=1);

namespace App\Api\Hashtag;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(operations: [new Get(uriTemplate: '/topics/{slug}', provider: HashtagViewProvider::class, openapi: new Operation(tags: ['Tópicos'], summary: 'Nome de exibição de um tópico com publicações visíveis.'))])]
final readonly class HashtagView
{
    public function __construct(public string $slug, public string $displayName)
    {
    }
}
