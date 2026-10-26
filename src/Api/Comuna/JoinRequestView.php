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
use App\Entity\ComunaJoinRequest;

/**
 * Pedido de entrada numa comuna privada. A pessoa pede e pode retirar o pedido; quem é
 * proprietário ou modera a comuna aprova (a participação é criada nesse momento) ou recusa.
 */
#[ApiResource(
    shortName: 'JoinRequest',
    operations: [
        new Put(
            uriTemplate: '/comunas/{slug}/join-request',
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            status: 200,
            read: false,
            deserialize: false,
            validate: false,
            security: "is_granted('ROLE_USER')",
            processor: JoinRequestProcessor::class,
            openapi: new Operation(
                tags: ['Comunas'],
                summary: 'Pede para entrar numa comuna privada (sem corpo; repetir não cria outro pedido).',
                description: 'Comuna pública: 409 /errors/join-request-not-needed (use PUT /membership). Já participa: 409 /errors/already-member. Arquivada: 409 /errors/comuna-archived. Limite de taxa por conta.',
            ),
        ),
        new Delete(
            uriTemplate: '/comunas/{slug}/join-request',
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            read: false,
            security: "is_granted('ROLE_USER')",
            processor: JoinRequestProcessor::class,
            openapi: new Operation(tags: ['Comunas'], summary: 'Retira o próprio pedido de entrada.'),
        ),
        new Post(
            uriTemplate: '/comunas/{slug}/join-requests/{id}/approve',
            uriVariables: [
                'slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug']),
                'id' => new Link(fromClass: self::class, identifiers: ['id']),
            ],
            status: 200,
            read: false,
            deserialize: false,
            validate: false,
            security: "is_granted('ROLE_USER')",
            processor: JoinRequestDecisionProcessor::class,
            openapi: new Operation(tags: ['Gestão de comunas'], summary: 'Aprova um pedido: a pessoa passa a participar (proprietário ou moderação).'),
        ),
        new Post(
            uriTemplate: '/comunas/{slug}/join-requests/{id}/reject',
            uriVariables: [
                'slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug']),
                'id' => new Link(fromClass: self::class, identifiers: ['id']),
            ],
            status: 200,
            read: false,
            deserialize: false,
            validate: false,
            security: "is_granted('ROLE_USER')",
            processor: JoinRequestDecisionProcessor::class,
            openapi: new Operation(tags: ['Gestão de comunas'], summary: 'Recusa um pedido (proprietário ou moderação).'),
        ),
    ],
)]
final readonly class JoinRequestView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $comunaSlug,
        public AuthorView $user,
        /** pending, approved, rejected ou cancelled. */
        public string $status,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $decidedAt,
    ) {
    }

    public static function fromRequest(ComunaJoinRequest $request): self
    {
        return new self(
            $request->getId()->toRfc4122(),
            $request->getComuna()->getSlug(),
            AuthorView::fromUser($request->getUser()),
            $request->getStatus()->value,
            $request->getCreatedAt(),
            $request->getDecidedAt(),
        );
    }
}
