<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\AuthSession;

/** Sessão aberta da própria conta, para "aparelhos conectados". Sem IP e sem tokens. */
#[ApiResource(
    shortName: 'Session',
    operations: [
        new GetCollection(
            uriTemplate: '/me/sessions',
            security: "is_granted('ROLE_USER')",
            provider: SessionProvider::class,
            openapi: new Operation(tags: ['Conta'], summary: 'Lista as sessões abertas da conta.'),
        ),
        new Delete(
            uriTemplate: '/me/sessions/{id}',
            security: "is_granted('ROLE_USER')",
            provider: SessionProvider::class,
            processor: SessionRevokeProcessor::class,
            openapi: new Operation(
                tags: ['Conta'],
                summary: 'Encerra uma sessão da própria conta.',
                description: 'Sessões de outras contas respondem 404, como se não existissem.',
            ),
        ),
    ],
)]
final readonly class SessionView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public ?string $deviceLabel,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $lastUsedAt,
        /** Se é a sessão que fez esta requisição. */
        public bool $current,
    ) {
    }

    public static function fromSession(AuthSession $session, ?AuthSession $current): self
    {
        return new self(
            $session->getId()->toRfc4122(),
            $session->getDeviceLabel(),
            $session->getCreatedAt(),
            $session->getLastUsedAt(),
            null !== $current && $current->getId()->equals($session->getId()),
        );
    }
}
