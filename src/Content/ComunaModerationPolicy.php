<?php

declare(strict_types=1);

namespace App\Content;

use App\Api\ApiProblem;
use App\Entity\Comuna;
use App\Entity\ComunaMembership;
use App\Entity\User;
use App\Enum\ComunaRole;
use App\Repository\ComunaMembershipRepository;

/**
 * Quem pode fazer o quê dentro de UMA comuna. O papel vem sempre do banco (comuna.owner e
 * comuna_membership.role), nunca do cliente, e vale só para aquela comuna: moderar uma comuna
 * não dá poder em outra nem na plataforma (ROLE_* é outro eixo, docs/moderacao.md).
 *
 * Proprietário: edita descrição e regras, nomeia e tira moderação, decide pedidos de entrada,
 * remove membros, modera conteúdo, arquiva, exclui (se for o único membro) e transfere a comuna.
 * Moderação: decide pedidos de entrada, remove membros comuns e modera conteúdo. Não mexe em
 * descrição, regras, moderação, propriedade, arquivamento ou exclusão.
 * Ninguém remove o proprietário nem modera o conteúdo dele por aqui.
 */
final readonly class ComunaModerationPolicy
{
    public function __construct(
        private ComunaMembershipRepository $memberships,
    ) {
    }

    public function assertOwner(Comuna $comuna, User $user): void
    {
        if (!$comuna->isOwnedBy($user)) {
            throw ApiProblem::forbidden('/errors/not-comuna-owner', 'Só quem é proprietário da comuna pode fazer isso.');
        }
    }

    /** Participação de quem modera (proprietário ou moderação) desta comuna, ou 403. */
    public function assertModerator(Comuna $comuna, User $user): ComunaMembership
    {
        $membership = $this->memberships->findMembership($comuna, $user);
        if (null === $membership || !$membership->getRole()->canModerate()) {
            throw ApiProblem::forbidden('/errors/not-comuna-moderator', 'Só a moderação desta comuna pode fazer isso.');
        }

        return $membership;
    }

    public function assertNotArchived(Comuna $comuna): void
    {
        if ($comuna->isArchived()) {
            throw self::archived();
        }
    }

    public static function archived(): ApiProblem
    {
        return ApiProblem::conflict(
            '/errors/comuna-archived',
            'Esta comuna está arquivada.',
            'O histórico continua disponível para leitura, mas nada novo pode ser publicado, comentado ou alterado.',
        );
    }

    /**
     * Dicas para a interface, calculadas só com o papel e o estado (sem consultas extras).
     * A decisão que vale é a de cada operação, que confere tudo de novo no servidor.
     *
     * @return array<string, bool>
     */
    public function permissions(Comuna $comuna, ?ComunaMembership $membership): array
    {
        $isMember = null !== $membership;
        $isOwner = $isMember && $comuna->isOwnedBy($membership->getUser());
        $moderates = $isMember && $membership->getRole()->canModerate();
        $active = !$comuna->isArchived();

        return [
            'canPost' => $isMember && $active,
            'canLeave' => $isMember && !$isOwner && ComunaRole::Founder !== $membership->getRole(),
            'canEditDetails' => $isOwner && $active,
            'canManageModerators' => $isOwner && $active,
            'canRemoveMembers' => $moderates,
            'canReviewJoinRequests' => $moderates && $active && !$comuna->isPublic(),
            'canModerateContent' => $moderates,
            'canArchive' => $isOwner && $active,
            'canDelete' => $isOwner && $active,
            'canTransferOwnership' => $isOwner && $active,
        ];
    }

    /**
     * Quem modera pode remover este conteúdo? Não vale para o próprio conteúdo (a autoria apaga)
     * nem para o do proprietário, a menos que seja o próprio proprietário quem remove.
     */
    public function canRemoveContentBy(Comuna $comuna, ?ComunaMembership $actor, User $author): bool
    {
        if (null === $actor || !$actor->getRole()->canModerate()) {
            return false;
        }
        if ($actor->getUser()->getId()->equals($author->getId())) {
            return false;
        }

        return !$comuna->isOwnedBy($author) || $comuna->isOwnedBy($actor->getUser());
    }
}
