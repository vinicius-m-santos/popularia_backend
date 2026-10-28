<?php

declare(strict_types=1);

namespace App\Square;

use App\Api\Profile\AuthorView;
use App\Api\Square\MessageView;
use App\Api\Square\SquareView;
use App\Entity\Square;
use App\Entity\SquareMessage;
use App\Entity\Sticker;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Repository\ComunaJoinRequestRepository;

final readonly class SquareViews
{
    public function __construct(private SquareAccess $access, private SquareQueries $queries, private ComunaJoinRequestRepository $requests)
    {
    }

    /** @return array{id: string, label: string, symbol: string, tone: string, packId: string} */
    public static function sticker(Sticker $s): array
    {
        return ['id' => $s->id->toRfc4122(), 'label' => $s->label, 'symbol' => $s->symbol, 'tone' => $s->tone, 'packId' => $s->pack->id->toRfc4122()];
    }

    /** @param list<SquareMessage> $messages
     * @return list<MessageView>
     */
    public function messages(array $messages, User $viewer): array
    {
        $hidden = $this->access->blocks->blockedEitherWayIds($viewer);
        $reactions = $this->queries->reactions($messages, $viewer);
        $comunas = [];
        foreach ($messages as $m) {
            if (null !== $m->square->comuna) {
                $comunas[$m->square->comuna->getId()->toRfc4122()] = $m->square->comuna;
            }
        }
        $memberships = $this->access->memberships->findForComunas($viewer, array_values($comunas));
        $visible = static fn (SquareMessage $m): bool => ContentStatus::Published === $m->status && $m->author->isActive() && !isset($hidden[$m->author->getId()->toRfc4122()]);

        return array_map(function (SquareMessage $m) use ($viewer, $hidden, $reactions, $memberships, $visible): MessageView {
            $shown = $visible($m);
            $authorHidden = isset($hidden[$m->author->getId()->toRfc4122()]) || !$m->author->isActive();
            $status = $authorHidden ? 'hidden' : $m->status->value;
            // Exclusão voluntária preserva autoria e horário para a conversa continuar legível,
            // sem devolver texto, figurinha, resposta, reações ou ações. Bloqueio/conta inativa
            // continua ocultando a autoria, e remoção da moderação continua sendo só marcador.
            $showDeletedAuthor = !$authorHidden && ContentStatus::Deleted === $m->status;
            $parent = $m->replyTo;
            $reply = null;
            if ($shown && null !== $parent) {
                $reply = ['id' => $parent->id->toRfc4122(), 'author' => $visible($parent) ? AuthorView::fromUser($parent->author) : null, 'body' => $visible($parent) ? mb_substr($parent->body ?? 'Figurinha', 0, 120) : null, 'status' => $visible($parent) ? 'published' : 'unavailable'];
            }
            $comuna = $m->square->comuna;
            $mine = $m->author->getId()->equals($viewer->getId());
            $active = 'active' === $m->square->status && null !== $comuna && !$comuna->isArchived();
            $canRemove = $shown && $active && $this->access->moderation->canRemoveContentBy($comuna, $memberships[$comuna->getId()->toRfc4122()] ?? null, $m->author);

            return new MessageView(
                $m->id->toRfc4122(), $m->square->id->toRfc4122(), $status,
                $shown ? $m->body : null,
                $shown || $showDeletedAuthor ? AuthorView::fromUser($m->author) : null,
                $shown && null !== $m->sticker ? self::sticker($m->sticker) : null,
                $reply, $shown ? ($reactions[$m->id->toRfc4122()] ?? []) : [],
                $m->createdAt, $m->editedAt, $m->revision, $mine ? $m->clientId : null,
                ['isAuthor' => ($shown || $showDeletedAuthor) && $mine, 'canDelete' => $shown && $mine && $active, 'canRemove' => $canRemove, 'canReport' => $shown && !$mine && $active, 'canReact' => $shown && $active && $viewer->isEmailVerified(), 'canReply' => $shown && $active && $viewer->isEmailVerified() && isset($memberships[$comuna->getId()->toRfc4122()])],
            );
        }, $messages);
    }

    /** @param list<Square> $squares
     * @return list<SquareView>
     */
    public function squares(array $squares, User $viewer, bool $detail = false): array
    {
        $comunas = array_values(array_filter(array_map(static fn (Square $s) => $s->comuna, $squares)));
        $memberships = $this->access->memberships->findForComunas($viewer, $comunas);
        $stats = $this->queries->stats($squares, $viewer);
        $lastIds = array_values(array_filter(array_map(static fn (array $s) => $s['lastMessageId'], $stats)));
        $last = [];
        // Cada consulta de mensagens abaixo usa todos os IDs da lista, sem consultas por item.
        if ([] !== $lastIds) {
            // IDs únicos entre praças; consulta já foi autorizada por stats.
            $messages = $this->queries->latestByIds($lastIds);
            foreach ($this->messages($messages, $viewer) as $m) {
                $last[$m->id] = $m;
            }
        }

        return array_map(function (Square $s) use ($viewer, $detail, $memberships, $stats, $last): SquareView {
            $c = $s->comuna;
            $membership = null === $c ? null : ($memberships[$c->getId()->toRfc4122()] ?? null);
            $readable = null !== $c && ($c->isPublic() || null !== $membership);
            $active = $readable && 'active' === $s->status && !$c->isArchived();
            $stat = $stats[$s->id->toRfc4122()] ?? null;
            $manage = null !== $c && null !== $membership && $membership->getRole()->canModerate() && !$c->isArchived();

            return new SquareView(
                $s->id->toRfc4122(),
                null === $c ? null : ['id' => $c->getId()->toRfc4122(), 'slug' => $c->getSlug(), 'name' => $c->getName(), 'tone' => $c->getTone(), 'visibility' => $c->getVisibility()->value],
                $readable ? ($s->title ?? $c->getName()) : ($c?->getName() ?? 'Praça'),
                $readable ? $s->status : null, $readable ? $s->closedAt : null,
                $readable ? $s->version : null,
                $readable ? ($stat['presence'] ?? 0) : null,
                $readable && null !== $membership ? ($stat['unread'] ?? 0) : null,
                $readable ? ($stat['lastReadId'] ?? null) : null,
                $readable ? ($last[$stat['lastMessageId'] ?? ''] ?? null) : null,
                ['canRead' => $readable, 'isMember' => null !== $membership, 'canWrite' => $active && null !== $membership && $viewer->isEmailVerified(), 'canManage' => $manage, 'joinRequest' => $detail && null !== $c && null === $membership && null !== $this->requests->findPending($c, $viewer) ? 'pending' : null],
            );
        }, $squares);
    }
}
