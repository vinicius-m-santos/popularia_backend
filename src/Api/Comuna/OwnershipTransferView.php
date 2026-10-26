<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Profile\AuthorView;
use App\Entity\ComunaOwnershipTransfer;

/**
 * Transferência de propriedade: o proprietário propõe, a pessoa escolhida aceita ou recusa.
 * Até o aceite nada muda; no aceite, a troca é feita de uma vez, numa transação.
 */
#[ApiResource(
    shortName: 'OwnershipTransfer',
    operations: [
        new Put(
            uriTemplate: '/comunas/{slug}/ownership-transfer',
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            status: 200,
            read: false,
            security: "is_granted('ROLE_USER')",
            input: OwnershipTransferInput::class,
            processor: OwnershipTransferProcessor::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Propõe passar a comuna para outro membro (só o proprietário).',
                description: 'Substitui uma proposta anterior em aberto. A pessoa precisa participar da comuna (409 /errors/target-not-member).',
            ),
        ),
        new Delete(
            uriTemplate: '/comunas/{slug}/ownership-transfer',
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            read: false,
            security: "is_granted('ROLE_USER')",
            processor: OwnershipTransferProcessor::class,
            openapi: new Operation(tags: ['Gestão de comunas'], summary: 'Retira a proposta em aberto (só o proprietário).'),
        ),
        new Post(
            uriTemplate: '/comunas/{slug}/ownership-transfer/accept',
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            status: 200,
            read: false,
            deserialize: false,
            validate: false,
            security: "is_granted('ROLE_USER')",
            processor: OwnershipTransferResponseProcessor::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Aceita a comuna (só quem recebeu a proposta).',
                description: 'Exige e-mail confirmado. O limite de 3 comunas ativas vale para quem recebe (409 /errors/comuna-limit-reached). Quem propôs vira membro comum.',
            ),
        ),
        new Post(
            uriTemplate: '/comunas/{slug}/ownership-transfer/decline',
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            status: 200,
            read: false,
            deserialize: false,
            validate: false,
            security: "is_granted('ROLE_USER')",
            processor: OwnershipTransferResponseProcessor::class,
            openapi: new Operation(tags: ['Gestão de comunas'], summary: 'Recusa a comuna (só quem recebeu a proposta).'),
        ),
    ],
)]
final readonly class OwnershipTransferView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $comunaSlug,
        public AuthorView $from,
        public AuthorView $to,
        /** pending, accepted, declined ou cancelled. */
        public string $status,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromTransfer(ComunaOwnershipTransfer $transfer): self
    {
        return new self(
            $transfer->getId()->toRfc4122(),
            $transfer->getComuna()->getSlug(),
            AuthorView::fromUser($transfer->getFromUser()),
            AuthorView::fromUser($transfer->getToUser()),
            $transfer->getStatus()->value,
            $transfer->getCreatedAt(),
        );
    }
}
