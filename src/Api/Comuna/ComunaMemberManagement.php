<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;

/**
 * Ações sobre uma pessoa da comuna, pelo @: remover e mudar o papel na moderação. Quem pode
 * fazer o quê é decidido no servidor (ComunaModerationPolicy); o cliente só diz quem.
 */
#[ApiResource(
    shortName: 'ComunaMemberManagement',
    operations: [
        new Delete(
            uriTemplate: '/comunas/{slug}/members/{handle}',
            uriVariables: [
                'slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug']),
                'handle' => new Link(fromClass: self::class, identifiers: ['handle']),
            ],
            read: false,
            security: "is_granted('ROLE_USER')",
            processor: MemberRemoveProcessor::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Remove alguém da comuna (proprietário ou moderação).',
                description: 'A moderação remove membros comuns; só o proprietário remove quem modera. Ninguém remove o proprietário (403 /errors/cannot-remove-owner). O acesso a conteúdo privado acaba na hora.',
            ),
        ),
        new Put(
            uriTemplate: '/comunas/{slug}/moderators/{handle}',
            uriVariables: [
                'slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug']),
                'handle' => new Link(fromClass: self::class, identifiers: ['handle']),
            ],
            status: 200,
            read: false,
            deserialize: false,
            validate: false,
            output: ComunaMemberView::class,
            security: "is_granted('ROLE_USER')",
            processor: ModeratorProcessor::class,
            openapi: new Operation(tags: ['Gestão de comunas'], summary: 'Nomeia um membro para a moderação (só o proprietário; sem corpo).'),
        ),
        new Delete(
            uriTemplate: '/comunas/{slug}/moderators/{handle}',
            uriVariables: [
                'slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug']),
                'handle' => new Link(fromClass: self::class, identifiers: ['handle']),
            ],
            read: false,
            security: "is_granted('ROLE_USER')",
            processor: ModeratorProcessor::class,
            openapi: new Operation(tags: ['Gestão de comunas'], summary: 'Tira alguém da moderação; a pessoa continua membro (só o proprietário).'),
        ),
    ],
)]
final readonly class ComunaMemberManagement
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $handle,
    ) {
    }
}
