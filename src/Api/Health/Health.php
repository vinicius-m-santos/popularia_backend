<?php

declare(strict_types=1);

namespace App\Api\Health;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;

/** Situação da API e do banco. Pública e sem detalhes internos (versões, hosts, erros). */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/health',
            provider: HealthProvider::class,
            openapi: new Operation(
                tags: ['Sistema'],
                summary: 'Verifica se a API e o banco de dados estão disponíveis.',
                description: 'Responde 200 quando tudo está ok e 503 quando o banco não responde.',
            ),
        ),
    ],
)]
final readonly class Health
{
    public function __construct(
        public string $status,
        public string $database,
    ) {
    }
}
