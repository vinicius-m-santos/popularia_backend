<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\Comuna;
use App\Entity\ComunaMembership;

/**
 * Comuna com regras e o estado de quem vê (viewer). Comunas privadas aparecem com nome e
 * descrição; só as publicações são restritas a quem participa.
 */
#[ApiResource(
    shortName: 'Comuna',
    operations: [
        new Get(
            uriTemplate: '/comunas/{slug}',
            provider: ComunaProvider::class,
            openapi: new Operation(tags: ['Comunas'], summary: 'Detalhes de uma comuna pelo endereço (slug).'),
        ),
    ],
)]
final readonly class ComunaView
{
    /**
     * @param list<string>                             $topics
     * @param list<array{title: string, body: string}> $rules
     * @param array{isMember: bool, role: ?string}     $viewer
     */
    public function __construct(
        #[ApiProperty(identifier: false)]
        public string $id,
        #[ApiProperty(identifier: true)]
        public string $slug,
        public string $name,
        public string $description,
        public string $place,
        /** public ou private. */
        public string $visibility,
        public string $tone,
        public array $topics,
        public int $foundedYear,
        public int $memberCount,
        public string $moderationNote,
        public array $rules,
        /** Estado de quem vê: participa? com qual papel (member, moderator, founder)? */
        public array $viewer,
    ) {
    }

    public static function fromComuna(Comuna $comuna, ?ComunaMembership $membership): self
    {
        return new self(
            $comuna->getId()->toRfc4122(),
            $comuna->getSlug(),
            $comuna->getName(),
            $comuna->getDescription(),
            $comuna->getPlace(),
            $comuna->getVisibility()->value,
            $comuna->getTone(),
            $comuna->getTopics(),
            $comuna->getFoundedYear(),
            $comuna->getMemberCount(),
            $comuna->getModerationNote(),
            array_map(static fn ($r) => ['title' => $r->getTitle(), 'body' => $r->getBody()], $comuna->getRules()),
            ['isMember' => null !== $membership, 'role' => $membership?->getRole()->value],
        );
    }
}
