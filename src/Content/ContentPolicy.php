<?php

declare(strict_types=1);

namespace App\Content;

use App\Api\ApiProblem;
use App\Entity\Comuna;
use App\Entity\Post;
use App\Entity\User;
use App\Repository\ComunaMembershipRepository;
use App\Repository\PostRepository;
use App\Repository\UserBlockRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Regras de acesso a comunas, publicações e comentários, num só lugar e sempre no servidor.
 * As listas aplicam as mesmas regras nas consultas (PostRepository); aqui ficam as decisões
 * sobre um item específico, com o motivo da recusa em formato problem+json.
 *
 * Regras:
 * - Ler uma comuna privada exige participar dela (também depois de arquivada).
 * - Publicar exige conta ativa, e-mail confirmado e, numa comuna, participar dela e ela não estar arquivada.
 * - Comentar exige poder ler a publicação, e-mail confirmado, não ter bloqueio com a autoria e,
 *   numa comuna, participar dela e ela não estar arquivada.
 * - Bloqueio vale nos dois sentidos: quem bloqueia e quem foi bloqueado não se veem.
 * - Só a autoria apaga a própria publicação (a remoção pela moderação é outro fluxo).
 */
final readonly class ContentPolicy
{
    public function __construct(
        private ComunaMembershipRepository $memberships,
        private UserBlockRepository $blocks,
        private PostRepository $posts,
        private \App\Repository\UserFollowRepository $follows,
    ) {
    }

    /** Publicação que $viewer pode ver, ou 404 (sem confirmar se ela existe). */
    public function findVisiblePost(string $id, ?User $viewer): Post
    {
        $post = Uuid::isValid($id) ? $this->posts->find(Uuid::fromString($id)) : null;
        if (null === $post || !$this->canViewPost($post, $viewer)) {
            throw ApiProblem::notFound('Publicação não encontrada.');
        }

        return $post;
    }

    public function canReadComuna(Comuna $comuna, ?User $viewer): bool
    {
        if ($comuna->isSuspended()) {
            return false;
        }

        return $comuna->isPublic() || null !== $this->memberships->findMembership($comuna, $viewer);
    }

    public function assertCanReadComuna(Comuna $comuna, ?User $viewer): void
    {
        if (!$this->canReadComuna($comuna, $viewer)) {
            throw ApiProblem::forbidden('/errors/comuna-private', 'Esta comuna é privada.', 'Só quem participa vê as publicações.');
        }
    }

    /** Publicação visível para $viewer? Se não, a API responde 404, sem confirmar que ela existe. */
    public function canViewPost(Post $post, ?User $viewer): bool
    {
        if (!$post->isPublished() || !$post->getAuthor()->isActive()) {
            return false;
        }
        $comuna = $post->getComuna();
        if (null !== $comuna && $comuna->isSuspended()) {
            return false;
        }
        if (null === $comuna && $post->getAuthor()->isPrivate() && (null === $viewer || (!$viewer->getId()->equals($post->getAuthor()->getId()) && !($this->follows->findFollow($viewer, $post->getAuthor())?->isApproved() ?? false)))) {
            return false;
        }
        if (null !== $comuna && !$this->canReadComuna($comuna, $viewer)) {
            return false;
        }

        return !$this->blocks->isBlockedEitherWay($viewer, $post->getAuthor());
    }

    public function assertCanPostIn(?Comuna $comuna, User $author): void
    {
        if (!$author->isEmailVerified()) {
            throw ApiProblem::emailNotVerified();
        }
        if (null !== $comuna && null === $this->memberships->findMembership($comuna, $author)) {
            throw ApiProblem::forbidden('/errors/not-a-member', 'Entre na comuna para publicar nela.');
        }
        if (null !== $comuna && $comuna->isSuspended()) {
            throw ComunaModerationPolicy::suspended();
        }
        if (null !== $comuna && $comuna->isArchived()) {
            throw ComunaModerationPolicy::archived();
        }
    }

    /** Motivo pelo qual $viewer não pode comentar (null = pode). Supõe que a publicação é visível. */
    public function whyCannotComment(Post $post, ?User $viewer): ?ApiProblem
    {
        if (null === $viewer) {
            return ApiProblem::unauthorized();
        }
        if (!$viewer->isEmailVerified()) {
            return ApiProblem::emailNotVerified();
        }
        $comuna = $post->getComuna();
        if (null !== $comuna && null === $this->memberships->findMembership($comuna, $viewer)) {
            return ApiProblem::forbidden('/errors/not-a-member', 'Entre na comuna para comentar.');
        }
        if (null !== $comuna && $comuna->isSuspended()) {
            return ComunaModerationPolicy::suspended();
        }
        if (null !== $comuna && $comuna->isArchived()) {
            return ComunaModerationPolicy::archived();
        }

        return null;
    }

    /** Motivo pelo qual $viewer não pode votar (null = pode). Supõe que a publicação é visível. */
    public function whyCannotVote(Post $post, User $viewer): ?ApiProblem
    {
        if (!$viewer->isEmailVerified()) {
            return ApiProblem::emailNotVerified();
        }
        $comuna = $post->getComuna();
        if (null !== $comuna && null === $this->memberships->findMembership($comuna, $viewer)) {
            return ApiProblem::forbidden('/errors/not-a-member', 'Entre na comuna para votar.');
        }
        if (null !== $comuna && $comuna->isSuspended()) {
            return ComunaModerationPolicy::suspended();
        }
        if (null !== $comuna && $comuna->isArchived()) {
            return ComunaModerationPolicy::archived();
        }

        return null;
    }

    public function canDeletePost(Post $post, ?User $viewer): bool
    {
        return null !== $viewer && $post->getAuthor()->getId()->equals($viewer->getId());
    }
}
