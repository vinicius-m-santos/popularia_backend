<?php

declare(strict_types=1);

namespace App\Api\Library;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(shortName: 'LibraryReports', security: "is_granted('ROLE_USER')", cacheHeaders: ['max_age' => 0, 'shared_max_age' => 0, 'public' => false], operations: [
    new Get(uriTemplate: '/library/reports', name: 'library_reports', provider: ReportProvider::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Denúncias da Biblioteca: comuna=slug exige moderador local; sem comuna exige moderação da plataforma.')),
    new Patch(uriTemplate: '/library/reports/{id}', name: 'library_decide_report', read: false, input: \App\Api\Square\ReportDecisionInput::class, output: false, status: 204, processor: ReportProcessor::class, openapi: new Operation(tags: ['Biblioteca'], summary: 'Resolve denúncia: dismissed ou removed; ação auditada.')),
])]
final readonly class ReportPage
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(public array $items, public ?string $nextCursor, #[ApiProperty(identifier: true)] public ?string $id = null)
    {
    }
}
