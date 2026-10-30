<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use App\Api\Profile\AuthorView;
use App\Api\Topic\TopicView;
use App\Content\ComunaModerationPolicy;
use App\Entity\Comuna;
use App\Entity\ComunaMembership;
use App\Entity\User;
use App\Enum\Topic;
use App\Media\StoredImageUrl;
use App\Repository\ComunaJoinRequestRepository;
use App\Repository\ComunaMembershipRepository;
use App\Repository\ComunaOwnershipTransferRepository;

/**
 * Monta ComunaView com o estado de quem vê. Nas listas, só o que sai sem consultas por item
 * (participação, papel e permissões); no detalhe, também a moderação, o pedido de entrada e a
 * proposta de transferência que envolvem quem vê.
 */
final readonly class ComunaViews
{
    public function __construct(
        private ComunaMembershipRepository $memberships,
        private ComunaJoinRequestRepository $joinRequests,
        private ComunaOwnershipTransferRepository $transfers,
        private ComunaModerationPolicy $policy,
    ) {
    }

    public function detail(Comuna $comuna, ?User $viewer): ComunaView
    {
        $membership = $this->memberships->findMembership($comuna, $viewer);
        $request = null === $membership ? $this->joinRequests->findPending($comuna, $viewer) : null;

        $transfer = null === $viewer ? null : $this->transferInvolving($comuna, $viewer);

        return $this->build(
            $comuna,
            $membership,
            array_map(ComunaMemberView::fromMembership(...), $this->memberships->findModeration($comuna)),
            null === $request ? null : 'pending',
            $transfer,
        );
    }

    /**
     * @param list<Comuna>                         $comunas
     * @param array<string, ComunaMembership>|null $mine        participações de quem vê, por ID da comuna (null = buscar)
     * @param array<string, true>                  $hiddenUsers identidades bloqueadas, omitidas nos previews de descoberta
     *
     * @return list<ComunaView>
     */
    public function many(array $comunas, ?User $viewer, ?array $mine = null, array $hiddenUsers = []): array
    {
        $mine ??= null === $viewer ? [] : $this->memberships->findForComunas($viewer, $comunas);

        return array_map(fn (Comuna $c) => $this->build($c, $mine[$c->getId()->toRfc4122()] ?? null, null, null, null, $hiddenUsers), $comunas);
    }

    public function one(Comuna $comuna, ?User $viewer): ComunaView
    {
        return $this->many([$comuna], $viewer)[0];
    }

    /**
     * Proposta de transferência em aberto, se quem vê for quem propôs (outgoing) ou quem recebe (incoming).
     *
     * @return array{direction: string, from: AuthorView, to: AuthorView, createdAt: \DateTimeImmutable}|null
     */
    private function transferInvolving(Comuna $comuna, User $viewer): ?array
    {
        $pending = $this->transfers->findPending($comuna);
        if (null === $pending) {
            return null;
        }
        $outgoing = $pending->getFromUser()->getId()->equals($viewer->getId());
        if (!$outgoing && !$pending->getToUser()->getId()->equals($viewer->getId())) {
            return null;
        }

        return [
            'direction' => $outgoing ? 'outgoing' : 'incoming',
            'from' => AuthorView::fromUser($pending->getFromUser()),
            'to' => AuthorView::fromUser($pending->getToUser()),
            'createdAt' => $pending->getCreatedAt(),
        ];
    }

    /**
     * @param list<ComunaMemberView>|null $moderation
     * @param array<string, mixed>|null   $transfer
     * @param array<string, true>         $hiddenUsers
     */
    private function build(Comuna $comuna, ?ComunaMembership $membership, ?array $moderation, ?string $joinRequest, ?array $transfer, array $hiddenUsers = []): ComunaView
    {
        $owner = $comuna->getOwner();
        $topic = Topic::tryFrom($comuna->getTopics()[0] ?? '');

        return new ComunaView(
            $comuna->getId()->toRfc4122(),
            $comuna->getSlug(),
            $comuna->getName(),
            $comuna->getDescription(),
            $comuna->getPlace(),
            $comuna->getVisibility()->value,
            $comuna->getStatus()->value,
            $comuna->getArchivedAt(),
            $comuna->getTone(),
            $comuna->getTopics(),
            $comuna->getFoundedYear(),
            $comuna->getMemberCount(),
            $comuna->getModerationNote(),
            array_map(static fn ($r) => ['title' => $r->getTitle(), 'body' => $r->getBody()], $comuna->getRules()),
            null === $owner || isset($hiddenUsers[$owner->getId()->toRfc4122()]) ? null : AuthorView::fromUser($owner),
            $moderation,
            [
                'isMember' => null !== $membership,
                'role' => $membership?->getRole()->value,
                'permissions' => $this->policy->permissions($comuna, $membership),
                'joinRequest' => $joinRequest,
                'ownershipTransfer' => $transfer,
            ],
            null === $topic ? null : TopicView::fromTopic($topic),
            StoredImageUrl::cover($comuna),
            StoredImageUrl::mark($comuna),
        );
    }
}
