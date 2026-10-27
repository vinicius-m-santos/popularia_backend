<?php

declare(strict_types=1);

namespace App\Api\Square;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Comuna\ComunaView;

#[ApiResource(
    operations: [
        new Patch(
            uriTemplate: '/comunas/{slug}/reports/{reportId}',
            name: 'square_resolve_report',
            security: "is_granted('ROLE_USER')",
            uriVariables: ['reportId' => new Link(fromClass: self::class, identifiers: ['id']), 'slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            read: false,
            processor: SquareProcessor::class,
            input: ReportDecisionInput::class,
            status: 204,
            output: false,
            openapi: new Operation(tags: ['Praças'], summary: 'Decide denúncia: dismissed ou removed.'),
        ),
    ],
)]
final readonly class ReportManagement
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
    ) {
    }
}
