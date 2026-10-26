<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post as PostOperation;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Profile\AuthorView;

/**
 * Publicação como o app a exibe. Autoria, contagens e "viewer" são calculados no servidor;
 * nada disso é aceito do cliente.
 */
#[ApiResource(
    shortName: 'Post',
    operations: [
        new Get(
            uriTemplate: '/posts/{id}',
            provider: PostProvider::class,
            openapi: new Operation(
                tags: ['Publicações'],
                summary: 'Uma publicação.',
                description: 'Apagada, removida, de comuna privada sem participação ou com bloqueio entre as pessoas: 404.',
            ),
        ),
        new PostOperation(
            uriTemplate: '/posts',
            status: 201,
            security: "is_granted('ROLE_USER')",
            input: PostInput::class,
            processor: PostCreateProcessor::class,
            openapi: new Operation(
                tags: ['Publicações'],
                summary: 'Publica numa comuna (comunaId) ou no próprio perfil (comunaId nulo).',
                description: 'Exige e-mail confirmado (403 /errors/email-not-verified) e participar da comuna (403 /errors/not-a-member). A autoria é sempre quem está logado. Limite de taxa por conta.',
            ),
        ),
        new PostOperation(
            uriTemplate: '/posts/{id}/removal',
            status: 204,
            read: false,
            output: false,
            security: "is_granted('ROLE_USER')",
            input: ContentRemovalInput::class,
            processor: PostRemovalProcessor::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Remove uma publicação da comuna (moderação da comuna), com motivo.',
                description: 'Só quem modera a comuna da publicação (403 /errors/not-comuna-moderator). Não vale para o próprio conteúdo nem para o do proprietário, salvo pelo próprio proprietário (403 /errors/cannot-moderate-content). Fica na auditoria.',
            ),
        ),
        new Delete(
            uriTemplate: '/posts/{id}',
            security: "is_granted('ROLE_USER')",
            provider: PostProvider::class,
            processor: PostDeleteProcessor::class,
            openapi: new Operation(tags: ['Publicações'], summary: 'Apaga a própria publicação (o texto sai do banco).'),
        ),
    ],
)]
final readonly class PostView
{
    /**
     * @param array{id: string, slug: string, name: string, status: string}|null        $comuna
     * @param array{isAuthor: bool, canComment: bool, canDelete: bool, canRemove: bool} $viewer
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $body,
        public \DateTimeImmutable $createdAt,
        public AuthorView $author,
        public ?array $comuna,
        public int $commentCount,
        public array $viewer,
    ) {
    }
}
