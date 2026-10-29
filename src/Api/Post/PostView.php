<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post as PostOperation;
use ApiPlatform\Metadata\Put;
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
        new Put(
            uriTemplate: '/posts/{id}/save',
            name: 'post_save',
            status: 204,
            read: false,
            deserialize: false,
            validate: false,
            output: false,
            security: "is_granted('ROLE_USER')",
            processor: SavedPostProcessor::class,
            openapi: new Operation(
                tags: ['Biblioteca'],
                summary: 'Guarda uma referência privada à publicação. Idempotente.',
                description: 'Só salva o que a pessoa pode ver (senão 404). Não avisa a autoria, não conta publicamente e não copia PDF nem mídia.',
            ),
        ),
        new Delete(
            uriTemplate: '/posts/{id}/save',
            name: 'post_unsave',
            status: 204,
            read: false,
            output: false,
            security: "is_granted('ROLE_USER')",
            processor: SavedPostProcessor::class,
            openapi: new Operation(
                tags: ['Biblioteca'],
                summary: 'Tira a publicação dos salvos. Idempotente, mesmo sem acesso atual.',
            ),
        ),
        new Put(
            uriTemplate: '/posts/{id}/poll/vote',
            status: 200,
            read: false,
            security: "is_granted('ROLE_USER')",
            input: PollVoteInput::class,
            output: PostView::class,
            processor: PollVoteProcessor::class,
            openapi: new Operation(
                tags: ['Publicações'],
                summary: 'Registra ou troca o voto único de quem está logado.',
                description: 'Exige e-mail confirmado e, numa comuna, participar dela. Repetir a mesma opção não altera o resultado. A resposta é a publicação já com a enquete atualizada.',
            ),
        ),
    ],
)]
final readonly class PostView
{
    /**
     * @param array{id: string, slug: string, name: string, status: string}|null                     $comuna
     * @param array{isAuthor: bool, canComment: bool, canDelete: bool, canRemove: bool, saved: bool} $viewer
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
        public ?string $topic = null,
        #[ApiProperty(readableLink: true)]
        public ?\App\Api\Library\DocumentView $document = null,
        #[ApiProperty(readableLink: true)]
        public ?\App\Api\PostMedia\PostMediaView $media = null,
        public ?PollView $poll = null,
    ) {
    }
}
