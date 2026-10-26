<?php

declare(strict_types=1);

namespace App\Api\Topic;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use App\Enum\Topic;

/** Assuntos oficiais (lista fixa, mantida pela equipe em App\Enum\Topic). */
#[ApiResource(
    shortName: 'Topic',
    operations: [
        new GetCollection(
            uriTemplate: '/topics',
            provider: TopicProvider::class,
            openapi: new Operation(tags: ['Assuntos'], summary: 'Lista os assuntos, na ordem e nos grupos das telas.'),
        ),
    ],
)]
final readonly class TopicView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $label,
        public string $shortLabel,
        public string $group,
    ) {
    }

    public static function fromTopic(Topic $topic): self
    {
        $d = $topic->describe();

        return new self($topic->value, $d['label'], $d['shortLabel'], $d['group']);
    }
}
