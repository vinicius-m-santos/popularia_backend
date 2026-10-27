<?php

declare(strict_types=1);

namespace App\Square;

use App\Api\ApiProblem;
use App\Api\Square\MessageInput;
use App\Api\Square\StickerInput;
use App\Auth\AdminAudit;
use App\Content\TextNormalizer;
use App\Entity\ContentReport;
use App\Entity\Square;
use App\Entity\SquareMessage;
use App\Entity\SquareReaction;
use App\Entity\Sticker;
use App\Entity\StickerPack;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Notification\NotificationCreator;
use App\Repository\ComunaRepository;
use App\Security\RateLimitGuard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Escritas serializadas por comuna e praça. Reutiliza papéis, bloqueios, rate limit e auditoria. */
final readonly class SquareService
{
    public const array REACTIONS = ['❤️', '✊', '😂', '😮', '😢', '👏', '🔥', '👍'];
    public const array SYMBOLS = ['✊', '📢', '❤️', '🤝', '🔥', '📚', '🌱', '☕', '💪', '🎉', '⭐', '💡'];

    public function __construct(
        private EntityManagerInterface $em,
        private SquareAccess $access,
        private \App\Content\RemovedContentFilter $filter,
        private ComunaRepository $comunas,
        private ClockInterface $clock,
        private RateLimitGuard $rateLimit,
        private AdminAudit $audit,
        private NotificationCreator $notifications,
    ) {
    }

    public function send(string $id, User $actor, MessageInput $input): SquareMessage
    {
        return $this->mutate($id, function (Square $s) use ($actor, $input): SquareMessage {
            $this->access->write($s, $actor);
            $comuna = $s->comuna ?? throw ApiProblem::notFound();
            $existing = $this->em->getRepository(SquareMessage::class)->findOneBy(['square' => $s, 'author' => $actor, 'clientId' => $input->clientId]);
            if (null !== $existing) {
                return $existing;
            }
            $this->filter->assertText($input->body ?? '', $comuna);
            $body = null === $input->body ? null : TextNormalizer::normalize($input->body);
            $body = '' === $body ? null : $body;
            if ((null === $body) === (null === $input->stickerId)) {
                throw new ApiProblem(422, '/errors/invalid-message', 'Envie texto ou uma figurinha.');
            }
            $sticker = null === $input->stickerId ? null : $this->em->find(Sticker::class, Uuid::fromString($input->stickerId));
            if (null !== $input->stickerId && (null === $sticker || !$sticker->active || (null !== $sticker->pack->comuna && !$sticker->pack->comuna->getId()->equals($comuna->getId())))) {
                throw new ApiProblem(422, '/errors/invalid-sticker', 'Figurinha indisponível nesta Praça.');
            }
            $parent = null === $input->replyToId ? null : $this->access->message($input->replyToId, $s, $actor);
            $this->rateLimit->consume('square_message', $actor->getUserIdentifier());
            $message = new SquareMessage($s, $actor, $body, $sticker, $parent, $this->clock->now(), $s->advance($this->clock->now()), $input->clientId);
            $this->em->persist($message);
            $this->notifications->squareReplied($message);

            return $message;
        });
    }

    public function delete(string $id, string $messageId, User $actor, ?string $reason = null): void
    {
        $this->mutate($id, function (Square $s) use ($messageId, $actor, $reason): void {
            $this->access->read($s, $actor);
            $this->access->active($s);
            $message = $this->access->message($messageId, $s, $actor, false);
            if (null === $reason) {
                if (!$message->author->getId()->equals($actor->getId())) {
                    throw ApiProblem::forbidden('/errors/not-message-author', 'Só a autoria pode excluir esta mensagem.');
                }
                if (ContentStatus::Published !== $message->status) {
                    return;
                }
                $message->status = ContentStatus::Deleted;
            } else {
                $this->remove($message, $actor, $reason);

                return;
            }
            $message->deletedAt = $this->clock->now();
            $message->revision = $s->advance($this->clock->now());
        });
    }

    private function remove(SquareMessage $message, User $actor, string $reason): void
    {
        $s = $message->square;
        $this->access->manage($s, $actor);
        $this->access->active($s);
        $comuna = $s->comuna ?? throw ApiProblem::notFound();
        $membership = $this->access->memberships->findMembership($comuna, $actor);
        if (!$this->access->moderation->canRemoveContentBy($comuna, $membership, $message->author)) {
            throw ApiProblem::forbidden('/errors/cannot-moderate-content', 'Você não pode remover este conteúdo.');
        }
        if (ContentStatus::Published !== $message->status) {
            return;
        }
        $message->status = ContentStatus::Removed;
        $message->deletedAt = $this->clock->now();
        $message->revision = $s->advance($this->clock->now());
        $this->audit->record($actor, 'comuna.square_message_removed', $message->author->getId(), TextNormalizer::normalize($reason), ['square_id' => $s->id->toRfc4122(), 'message_id' => $message->id->toRfc4122()]);
    }

    public function reaction(string $id, string $messageId, string $emoji, User $actor, bool $active): SquareMessage
    {
        return $this->mutate($id, function (Square $s) use ($messageId, $emoji, $actor, $active): SquareMessage {
            $this->access->read($s, $actor);
            $this->access->active($s);
            if (!$actor->isEmailVerified()) {
                throw ApiProblem::emailNotVerified();
            }
            if (!in_array($emoji, self::REACTIONS, true)) {
                throw new ApiProblem(422, '/errors/invalid-reaction', 'Reação inválida.');
            }
            $message = $this->access->message($messageId, $s, $actor);
            $reaction = $this->em->getRepository(SquareReaction::class)->findOneBy(['message' => $message, 'user' => $actor, 'emoji' => $emoji]);
            if ($active === (null !== $reaction)) {
                return $message;
            }
            $this->rateLimit->consume('square_interaction', $actor->getUserIdentifier());
            if ($active) {
                $this->em->persist(new SquareReaction($message, $actor, $emoji));
            } elseif (null !== $reaction) {
                $this->em->remove($reaction);
            }
            $message->revision = $s->advance($this->clock->now());

            return $message;
        });
    }

    /** @return list<array<string, mixed>> */
    public function packs(Square $square, User $viewer): array
    {
        $this->access->read($square, $viewer);
        /** @var list<StickerPack> $packs */
        $packs = $this->em->createQueryBuilder()->select('p')->from(StickerPack::class, 'p')->where('p.comuna IS NULL OR p.comuna = :comuna')->setParameter('comuna', $square->comuna)->orderBy('p.scope', 'DESC')->getQuery()->getResult();
        $stickers = $this->em->getRepository(Sticker::class)->findBy(['pack' => $packs, 'active' => true], ['id' => 'ASC']);

        return array_map(static fn (StickerPack $p) => ['id' => $p->id->toRfc4122(), 'name' => $p->name, 'scope' => $p->scope, 'stickers' => array_values(array_map(SquareViews::sticker(...), array_filter($stickers, static fn (Sticker $s) => $s->pack->id->equals($p->id))))], $packs);
    }

    public function addSticker(string $id, User $actor, StickerInput $input): void
    {
        $this->mutate($id, function (Square $s) use ($actor, $input): void {
            $this->access->manage($s, $actor);
            $this->access->active($s);
            $pack = $this->em->getRepository(StickerPack::class)->findOneBy(['comuna' => $s->comuna]) ?? throw ApiProblem::notFound();
            if ($this->em->getRepository(Sticker::class)->count(['pack' => $pack, 'active' => true]) >= 40) {
                throw ApiProblem::conflict('/errors/sticker-limit', 'O pacote pode ter até 40 figurinhas.');
            }
            $this->em->persist(new Sticker($pack, TextNormalizer::normalize($input->label), $input->symbol, $input->tone));
            $this->audit->record($actor, 'square.sticker_added', null, '', ['square_id' => $s->id->toRfc4122(), 'pack_id' => $pack->id->toRfc4122()]);
        });
    }

    public function deleteSticker(string $id, string $stickerId, User $actor): void
    {
        $this->mutate($id, function (Square $s) use ($actor, $stickerId): void {
            $this->access->manage($s, $actor);
            $this->access->active($s);
            $comuna = $s->comuna ?? throw ApiProblem::notFound();
            $sticker = Uuid::isValid($stickerId) ? $this->em->find(Sticker::class, Uuid::fromString($stickerId)) : null;
            if (null === $sticker || null === $sticker->pack->comuna || !$sticker->pack->comuna->getId()->equals($comuna->getId())) {
                throw ApiProblem::notFound();
            }
            $sticker->active = false;
            $this->audit->record($actor, 'square.sticker_removed', null, '', ['square_id' => $s->id->toRfc4122(), 'sticker_id' => $sticker->id->toRfc4122()]);
        });
    }

    public function report(string $id, string $messageId, User $actor, string $reason): void
    {
        $this->mutate($id, function (Square $s) use ($messageId, $actor, $reason): void {
            $this->access->active($s);
            $message = $this->access->message($messageId, $s, $actor);
            if ($message->author->getId()->equals($actor->getId())) {
                throw ApiProblem::forbidden('/errors/own-report', 'Não é possível denunciar sua própria mensagem.');
            }
            $this->rateLimit->consume('square_interaction', $actor->getUserIdentifier());
            if (null === $this->em->getRepository(ContentReport::class)->findOneBy(['reporter' => $actor, 'targetType' => 'square_message', 'targetId' => $message->id])) {
                $this->em->persist(new ContentReport($s->comuna ?? throw ApiProblem::notFound(), $actor, 'square_message', $message->id, TextNormalizer::normalize($reason), $this->clock->now()));
            }
        });
    }

    public function resolveReport(string $slug, string $reportId, User $actor, string $status): void
    {
        $comuna = $this->comunas->findOneBySlug($slug) ?? throw ApiProblem::notFound();
        $this->access->moderation->assertModerator($comuna, $actor);
        $report = Uuid::isValid($reportId) ? $this->em->find(ContentReport::class, Uuid::fromString($reportId)) : null;
        if (null === $report || 'square_message' !== $report->targetType || null === $report->comuna || !$report->comuna->getId()->equals($comuna->getId())) {
            throw ApiProblem::notFound();
        }
        $message = $this->em->find(SquareMessage::class, $report->targetId) ?? throw ApiProblem::notFound();
        $this->mutate($message->square->id->toRfc4122(), function (Square $s) use ($actor, $report, $message, $status): void {
            $this->access->manage($s, $actor);
            if ('removed' === $status) {
                $this->access->message($message->id->toRfc4122(), $s, $actor, false);
            }
            if ('pending' !== $report->status) {
                return;
            }
            if ('removed' === $status) {
                $this->remove($message, $actor, $report->reason);
            }
            $report->status = $status;
            $report->resolvedAt = $this->clock->now();
            $this->audit->record($actor, 'content.report_resolved', $message->author->getId(), $report->reason, ['report_id' => $report->id->toRfc4122(), 'status' => $status]);
        });
    }

    /** @template T
     * @param callable(Square): T $callback
     *
     * @return T
     */
    private function mutate(string $id, callable $callback): mixed
    {
        return $this->em->getConnection()->transactional(function () use ($id, $callback): mixed {
            $square = $this->access->find($id);
            if (null !== $square->comuna) {
                $this->comunas->lock($square->comuna);
            }
            $this->em->refresh($square, LockMode::PESSIMISTIC_WRITE);
            $result = $callback($square);
            $this->em->flush();

            return $result;
        });
    }
}
