<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Profile\AuthorView;
use App\Api\Topic\TopicView;

/**
 * Comuna com regras e o estado de quem vê (viewer). Comunas privadas aparecem com nome e
 * descrição; só as publicações e a lista de membros são restritas a quem participa.
 * Proprietário, papel e permissões são sempre calculados no servidor.
 */
#[ApiResource(
    shortName: 'Comuna',
    operations: [
        new Get(
            uriTemplate: '/comunas/{slug}',
            provider: ComunaProvider::class,
            openapi: new Operation(tags: ['Comunas'], summary: 'Detalhes de uma comuna pelo endereço (slug).'),
        ),
        new Post(
            uriTemplate: '/comunas',
            status: 201,
            security: "is_granted('ROLE_USER')",
            input: ComunaCreateInput::class,
            processor: ComunaCreateProcessor::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Cria uma comuna; quem cria vira proprietário.',
                description: 'Exige conta ativa e e-mail confirmado (403 /errors/email-not-verified). No máximo 3 comunas ativas por pessoa (409 /errors/comuna-limit-reached), sem cooldown. Arquivar ou excluir libera a vaga imediatamente. O endereço (slug) é gerado do nome e precisa ser único (422 em "name"). Nome e slug não mudam depois.',
            ),
        ),
        new Patch(
            uriTemplate: '/comunas/{slug}',
            read: false,
            security: "is_granted('ROLE_USER')",
            input: ComunaUpdateInput::class,
            processor: ComunaUpdateProcessor::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Edita descrição, assunto principal e regras (só o proprietário).',
                description: 'Nome, endereço, visibilidade e proprietário não mudam por aqui (campos desconhecidos: 400). Comuna arquivada: 409 /errors/comuna-archived.',
            ),
        ),
        new Post(
            uriTemplate: '/comunas/{slug}/archive',
            status: 200,
            read: false,
            deserialize: false,
            validate: false,
            security: "is_granted('ROLE_USER')",
            processor: ComunaArchiveProcessor::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Arquiva a comuna (só o proprietário; definitivo pelo app).',
                description: 'O histórico fica para leitura de quem participa (comuna privada continua fechada). Sem novas publicações, comentários, membros ou pedidos. Libera uma vaga no limite de comunas ativas.',
            ),
        ),
        new Delete(
            uriTemplate: '/comunas/{slug}',
            read: false,
            security: "is_granted('ROLE_USER')",
            processor: ComunaDeleteProcessor::class,
            openapi: new Operation(
                tags: ['Gestão de comunas'],
                summary: 'Exclui a comuna definitivamente (só o proprietário, se for o único membro).',
                description: 'Com outros membros: 409 /errors/comuna-has-members. Com publicações ou comentários de outras pessoas, ou conteúdo removido pela moderação: 409 /errors/comuna-has-content. Nos dois casos, a alternativa é arquivar. Comuna arquivada: 409 /errors/comuna-archived.',
            ),
        ),
    ],
)]
final readonly class ComunaView
{
    /**
     * @param list<string>                             $topics
     * @param list<array{title: string, body: string}> $rules
     * @param list<ComunaMemberView>|null              $moderation
     * @param array<string, mixed>                     $viewer
     */
    public function __construct(
        #[ApiProperty(identifier: false)]
        public string $id,
        #[ApiProperty(identifier: true)]
        public string $slug,
        public string $name,
        public string $description,
        public string $place,
        /** public ou private. */
        public string $visibility,
        /** active ou archived. */
        public string $status,
        public ?\DateTimeImmutable $archivedAt,
        public string $tone,
        public array $topics,
        public int $foundedYear,
        public int $memberCount,
        public string $moderationNote,
        public array $rules,
        /** Quem responde pela comuna; null em comunas da equipe da plataforma. */
        public ?AuthorView $owner,
        /** Proprietário e moderação (dado público). Só no detalhe; null nas listas. */
        #[ApiProperty(readableLink: true)]
        public ?array $moderation,
        /**
         * Estado de quem vê: isMember, role (member, moderator, founder), permissions (dicas para a
         * interface), joinRequest (pending ou null) e ownershipTransfer (proposta em aberto que
         * envolve quem vê). joinRequest e ownershipTransfer só vêm preenchidos no detalhe.
         */
        public array $viewer,
        #[ApiProperty(readableLink: true)]
        public ?TopicView $primaryTopic = null,
    ) {
    }
}
