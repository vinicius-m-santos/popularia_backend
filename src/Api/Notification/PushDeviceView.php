<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;

#[ApiResource(operations: [
    new Put(uriTemplate: '/me/push-devices/{id}', status: 200, read: false, security: "is_granted('ROLE_USER')", input: PushDeviceInput::class, processor: PushDeviceProcessor::class, openapi: new Operation(tags: ['Notificações'], summary: 'Registra/atualiza esta instalação e sua sessão.')),
    new Delete(uriTemplate: '/me/push-devices/{id}', read: false, deserialize: false, validate: false, security: "is_granted('ROLE_USER')", processor: PushDeviceProcessor::class, openapi: new Operation(tags: ['Notificações'], summary: 'Desativa uma instalação própria (idempotente).')),
])]
final readonly class PushDeviceView
{
    public function __construct(public string $id)
    {
    }
}
