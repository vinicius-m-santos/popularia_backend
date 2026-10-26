<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;

/** Participar ou sair de uma comuna. Repetir a chamada não muda nada (idempotente). */
#[ApiResource(
    shortName: 'Membership',
    operations: [
        new Put(
            uriTemplate: '/comunas/{slug}/membership',
            status: 200,
            read: false,
            deserialize: false,
            validate: false,
            security: "is_granted('ROLE_USER')",
            processor: JoinComunaProcessor::class,
            openapi: new Operation(
                tags: ['Comunas'],
                summary: 'Entra na comuna (sem corpo).',
                description: 'Comunas privadas exigem aprovação: 403 /errors/comuna-requires-approval (peça em PUT /comunas/{slug}/join-request). Comuna arquivada: 409 /errors/comuna-archived.',
            ),
        ),
        new Delete(
            uriTemplate: '/comunas/{slug}/membership',
            read: false,
            security: "is_granted('ROLE_USER')",
            processor: LeaveComunaProcessor::class,
            openapi: new Operation(
                tags: ['Comunas'],
                summary: 'Sai da comuna.',
                description: 'Quem é proprietário não sai por aqui (409 /errors/founder-cannot-leave), para a comuna não ficar sem responsável: transfira ou arquive antes. Sair de uma comuna privada encerra o acesso ao conteúdo dela.',
            ),
        ),
    ],
)]
final readonly class MembershipView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $slug,
        public bool $isMember,
        public ?string $role,
        public int $memberCount,
    ) {
    }
}
