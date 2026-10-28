<?php

declare(strict_types=1);

namespace App\Api\Comment;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post as PostOperation;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Post\ContentRemovalInput;
use App\Api\Post\PostView;
use App\Api\Profile\AuthorView;
use App\Entity\Comment;

/**
 * Comentário em lista plana (o app monta a árvore pelo parentId, como já faz com os mocks).
 * Comentários apagados, removidos ou de quem tem bloqueio com você continuam na lista como
 * marcadores sem texto e sem autoria (status deleted/removed/hidden), para as respostas não
 * ficarem soltas.
 */
#[ApiResource(
    shortName: 'Comment',
    operations: [
        new PostOperation(
            uriTemplate: '/posts/{id}/comments',
            uriVariables: ['id' => new Link(fromClass: PostView::class, identifiers: ['id'])],
            status: 201,
            read: false,
            security: "is_granted('ROLE_USER')",
            input: CommentInput::class,
            processor: CommentCreateProcessor::class,
            openapi: new Operation(
                tags: ['Comentários'],
                summary: 'Comenta numa publicação ou responde a um comentário (parentId).',
                description: 'Mesmas regras de publicar: e-mail confirmado e participar da comuna. O comentário pai precisa ser da mesma publicação.',
            ),
        ),
        new Put(
            uriTemplate: '/comments/{id}/support',
            name: 'comment_support',
            security: "is_granted('ROLE_USER')",
            read: false,
            input: false,
            deserialize: false,
            validate: false,
            processor: CommentSupportProcessor::class,
            openapi: new Operation(tags: ['Comentários'], summary: 'Apoia um comentário; operação idempotente.'),
        ),
        new Delete(
            uriTemplate: '/comments/{id}/support',
            name: 'comment_unsupport',
            status: 200,
            security: "is_granted('ROLE_USER')",
            read: false,
            input: false,
            output: CommentView::class,
            deserialize: false,
            validate: false,
            processor: CommentSupportProcessor::class,
            openapi: new Operation(tags: ['Comentários'], summary: 'Remove o próprio apoio de um comentário; operação idempotente.'),
        ),
        new PostOperation(
            uriTemplate: '/comments/{id}/removal',
            status: 204,
            read: false,
            output: false,
            security: "is_granted('ROLE_USER')",
            input: ContentRemovalInput::class,
            processor: CommentRemovalProcessor::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Remove um comentário (moderação da comuna), com motivo.',
                description: 'Mesmas regras da remoção de publicações. O comentário vira um marcador "removed" na conversa.',
            ),
        ),
    ],
)]
final readonly class CommentView
{
    /** @param array{isAuthor: bool, canRemove: bool} $viewer */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $postId,
        public ?string $parentId,
        /** published, deleted, removed ou hidden (bloqueio). Só "published" traz texto e autoria. */
        public string $status,
        public ?string $body,
        public ?AuthorView $author,
        public \DateTimeImmutable $createdAt,
        public int $supportCount,
        public array $viewer,
    ) {
    }

    /** $canRemove: quem vê modera a comuna e pode remover este comentário (dica; a operação confere de novo). */
    public static function fromComment(Comment $comment, bool $hiddenByBlock, ?string $viewerId, bool $canRemove = false, int $supportCount = 0, bool $supported = false): self
    {
        $status = $hiddenByBlock && $comment->isPublished() ? 'hidden' : $comment->getStatus()->value;
        $visible = 'published' === $status;

        return new self(
            $comment->getId()->toRfc4122(),
            $comment->getPost()->getId()->toRfc4122(),
            $comment->getParent()?->getId()->toRfc4122(),
            $status,
            $visible ? $comment->getBody() : null,
            $visible ? AuthorView::fromUser($comment->getAuthor()) : null,
            $comment->getCreatedAt(),
            $visible ? $supportCount : 0,
            [
                'isAuthor' => $visible && $comment->getAuthor()->getId()->toRfc4122() === $viewerId,
                'canRemove' => $visible && $canRemove,
                'supported' => $visible && $supported,
            ],
        );
    }
}
