<?php

declare(strict_types=1);

namespace App\Api\Block;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;

/**
 * Bloqueios da própria conta. Valem no servidor nos dois sentidos: publicações e comentários
 * somem dos feeds, perfis e conversas de ambas as pessoas. A pessoa bloqueada não é avisada.
 */
#[ApiResource(
    shortName: 'Block',
    operations: [
        new GetCollection(
            uriTemplate: '/me/blocks',
            security: "is_granted('ROLE_USER')",
            provider: BlockProvider::class,
            openapi: new Operation(tags: ['Conta'], summary: 'Pessoas que você bloqueou.'),
        ),
        new Put(
            uriTemplate: '/me/blocks/{handle}',
            status: 204,
            read: false,
            deserialize: false,
            validate: false,
            output: false,
            security: "is_granted('ROLE_USER')",
            processor: BlockProcessor::class,
            openapi: new Operation(tags: ['Conta'], summary: 'Bloqueia uma pessoa pelo @ (sem corpo; repetir não muda nada).'),
        ),
        new Delete(
            uriTemplate: '/me/blocks/{handle}',
            read: false,
            security: "is_granted('ROLE_USER')",
            processor: UnblockProcessor::class,
            openapi: new Operation(tags: ['Conta'], summary: 'Desfaz o bloqueio.'),
        ),
    ],
)]
final readonly class BlockView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $handle,
        public string $displayName,
        public \DateTimeImmutable $blockedAt,
    ) {
    }
}
