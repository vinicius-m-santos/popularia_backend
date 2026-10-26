<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;

/**
 * Situação da pessoa logada para criar comunas. Serve só para o app explicar antes de abrir o
 * formulário: POST /comunas confere tudo de novo, sob trava.
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/me/comuna-quota',
            security: "is_granted('ROLE_USER')",
            provider: ComunaQuotaProvider::class,
            openapi: new Operation(tags: ['Conta'], summary: 'Quantas comunas ativas você possui e quando pode criar a próxima.'),
        ),
    ],
)]
final readonly class ComunaQuota
{
    public function __construct(
        public int $activeOwned,
        public int $limit,
        public int $intervalDays,
        /** Data a partir da qual uma nova criação é aceita; null se não há espera. */
        public ?\DateTimeImmutable $nextCreationAt,
        public bool $canCreate,
        /** type do erro que POST /comunas responderia agora (null = pode criar). */
        public ?string $blockedBy,
    ) {
    }
}
