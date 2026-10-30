<?php

declare(strict_types=1);

namespace App\Content;

use App\Api\ApiProblem;
use App\Api\Moderation\ReportItem;
use App\Api\Moderation\ReportQueue;
use App\Api\Moderation\ReportSubject;
use App\Api\Pagination;
use App\Auth\AdminAudit;
use App\Auth\SessionManager;
use App\Entity\Comment;
use App\Entity\Comuna;
use App\Entity\ContentReport;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\PlatformRole;
use App\Enum\ReportReason;
use App\Repository\ComunaRepository;
use App\Repository\UserBlockRepository;
use App\Security\RateLimitGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Denúncia e decisão. A fila local vê só post e comentário da própria comuna.
 * Perfil, comuna inteira, publicação sem comuna e o que foi escalado vão para a plataforma.
 * Quem denunciou não sai na resposta.
 */
final readonly class ReportModeration
{
    /** @var list<string> */
    private const array TYPES = ['post', 'comment', 'profile', 'comuna'];

    public function __construct(
        private EntityManagerInterface $em,
        private ContentPolicy $policy,
        private ComunaModerationPolicy $moderation,
        private ComunaGovernance $governance,
        private ComunaPins $pins,
        private ComunaRepository $comunas,
        private UserBlockRepository $blocks,
        private AdminAudit $audit,
        private SessionManager $sessions,
        private RateLimitGuard $limits,
        private ClockInterface $clock,
    ) {
    }

    public function submit(User $actor, string $targetType, string $targetId, string $reasonCode, string $description): void
    {
        $this->limits->consume('report', $actor->getUserIdentifier());
        $reason = ReportReason::tryFrom($reasonCode) ?? throw ApiProblem::badRequest('/errors/invalid-report-reason', 'Escolha um motivo da lista.');
        $description = $this->description($description);
        $target = $this->resolveTarget($actor, $targetType, $targetId);
        if ($target['self']) {
            throw ApiProblem::conflict('/errors/cannot-report-self', 'Você não pode denunciar o próprio conteúdo.');
        }
        if ($this->hasOpenReport($actor, $targetType, $target['id'])) {
            return;
        }
        $report = new ContentReport(
            $target['comuna'],
            $actor,
            $targetType,
            $target['id'],
            $description,
            $this->clock->now(),
            $reason->value,
            $target['snapshot'],
        );
        try {
            $this->em->persist($report);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->em->detach($report);
        }
    }

    public function queueForComuna(Comuna $comuna, User $actor, string $bucket, Pagination $page): ReportQueue
    {
        if ($comuna->isSuspended()) {
            throw ComunaModerationPolicy::suspended();
        }
        $this->moderation->assertModerator($comuna, $actor);

        return $this->queue($this->rows($page, $bucket, 'r.comuna = :comuna AND r.scope = :scope AND r.targetType IN (:types)', [
            'comuna' => $comuna,
            'scope' => 'comuna',
            'types' => ['post', 'comment'],
        ]), $page, $actor);
    }

    public function queueForPlatform(User $actor, string $bucket, Pagination $page): ReportQueue
    {
        $this->assertPlatform($actor);

        return $this->queue($this->rows($page, $bucket, "(r.scope = 'platform' OR r.escalated = true OR c.status = 'suspended') AND r.targetType IN (:types)", [
            'types' => self::TYPES,
        ]), $page, $actor);
    }

    public function show(User $actor, string $id): ReportItem
    {
        $report = $this->find($id);
        $this->assertCanSee($actor, $report);

        return $this->items([$report], $actor)[0];
    }

    public function decide(User $actor, string $id, string $action, string $note): void
    {
        $note = $this->description($note);
        $this->em->wrapInTransaction(function () use ($actor, $id, $action, $note): void {
            $report = $this->lock($id);
            $this->assertCanSee($actor, $report);
            match ($action) {
                'claim' => $this->claim($actor, $report),
                'dismiss' => $this->dismiss($actor, $report, $note),
                'remove' => $this->remove($actor, $report, $note, true),
                'remove_and_escalate' => $this->removeAndEscalate($actor, $report, $note),
                'escalate' => $this->escalate($actor, $report, $note),
                'restore' => $this->restore($actor, $report, $note),
                'suspend' => $this->suspendAccount($actor, $report, $note),
                'reactivate' => $this->reactivateAccount($actor, $report, $note),
                'suspend_comuna' => $this->suspendComuna($actor, $report, $note),
                'reactivate_comuna' => $this->reactivateComuna($actor, $report, $note),
                default => throw ApiProblem::badRequest('/errors/invalid-moderation-action', 'Essa ação não existe.'),
            };
        });
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<ContentReport>
     */
    private function rows(Pagination $page, string $bucket, string $where, array $params): array
    {
        [$statuses, $direction] = $this->bucket($bucket);
        $qb = $this->em->createQueryBuilder()
            ->select('r', 'c')
            ->from(ContentReport::class, 'r')
            ->leftJoin('r.comuna', 'c')
            ->where($where)
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('statuses', $statuses)
            ->orderBy('r.id', $direction)
            ->setMaxResults($page->limit + 1);
        foreach ($params as $name => $value) {
            $qb->setParameter($name, $value);
        }
        if (null !== $page->cursor) {
            $qb->andWhere('ASC' === $direction ? 'r.id > :cursor' : 'r.id < :cursor')
                ->setParameter('cursor', $page->cursor, 'uuid');
        }

        /** @var list<ContentReport> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /** @param list<ContentReport> $rows */
    private function queue(array $rows, Pagination $page, User $actor): ReportQueue
    {
        [$items, $next] = $page->slice($rows, static fn (ContentReport $report): Uuid => $report->id);

        return new ReportQueue($this->items($items, $actor), $next);
    }

    /**
     * @param list<ContentReport> $reports
     *
     * @return list<ReportItem>
     */
    private function items(array $reports, User $actor): array
    {
        $posts = $this->loadPosts($reports);
        $comments = $this->loadComments($reports);
        $users = $this->loadUsers($reports);
        $comunas = $this->loadComunas($reports);
        $items = [];
        foreach ($reports as $report) {
            $items[] = $this->item($report, $actor, $posts, $comments, $users, $comunas);
        }

        return $items;
    }

    /**
     * @param array<string, Post>    $posts
     * @param array<string, Comment> $comments
     * @param array<string, User>    $users
     * @param array<string, Comuna>  $comunas
     */
    private function item(ContentReport $report, User $actor, array $posts, array $comments, array $users, array $comunas): ReportItem
    {
        $key = $report->targetId->toRfc4122();
        $author = null;
        $available = false;
        $label = $report->snapshot;
        if ('post' === $report->targetType && isset($posts[$key])) {
            $post = $posts[$key];
            $available = $post->isPublished();
            $author = $post->getAuthor();
            $label ??= $this->excerpt($post->getBody());
        } elseif ('comment' === $report->targetType && isset($comments[$key])) {
            $comment = $comments[$key];
            $available = $comment->isPublished();
            $author = $comment->getAuthor();
            $label ??= $this->excerpt($comment->getBody());
        } elseif ('profile' === $report->targetType && isset($users[$key])) {
            $user = $users[$key];
            $available = AccountStatus::Deleted !== $user->getStatus();
            $author = $user;
            $label ??= $user->getDisplayName().' @'.$user->getHandle();
        } elseif ('comuna' === $report->targetType && isset($comunas[$key])) {
            $comuna = $comunas[$key];
            $available = !$comuna->isArchived();
            $label ??= $comuna->getName().' ('.$comuna->getSlug().')';
        }
        $code = null === $report->reasonCode ? null : ReportReason::tryFrom($report->reasonCode);

        return new ReportItem(
            $report->id->toRfc4122(),
            $report->targetType,
            $key,
            $report->reasonCode,
            $code?->label() ?? $report->reason,
            '' === $report->reason ? null : $report->reason,
            $report->snapshot,
            $report->status,
            $report->escalated,
            $report->scope,
            $report->createdAt,
            $available,
            $label,
            null === $report->comuna ? null : $report->comuna->getSlug(),
            null === $author || AccountStatus::Deleted === $author->getStatus() ? null : new ReportSubject($author->getId()->toRfc4122(), $author->getHandle(), $author->getDisplayName()),
            $this->actions($actor, $report, $posts, $comments, $users, $comunas),
        );
    }

    /**
     * @param array<string, Post>    $posts
     * @param array<string, Comment> $comments
     * @param array<string, User>    $users
     * @param array<string, Comuna>  $comunas
     *
     * @return list<string>
     */
    private function actions(User $actor, ContentReport $report, array $posts, array $comments, array $users, array $comunas): array
    {
        $platform = $this->isPlatform($actor);
        $local = $this->isLocalModerator($actor, $report);
        $actions = [];
        if ($report->isOpen() && ($platform || ($local && !$report->escalated))) {
            $actions[] = 'claim';
            $actions[] = 'dismiss';
        }
        if ($report->isOpen() && $local && 'comuna' === $report->scope && \in_array($report->targetType, ['post', 'comment'], true)) {
            $actions[] = 'escalate';
            if (!$report->escalated) {
                $actions[] = 'remove_and_escalate';
            }
        }
        if (($platform || ($local && !$report->escalated)) && \in_array($report->targetType, ['post', 'comment'], true)) {
            $content = 'post' === $report->targetType ? ($posts[$report->targetId->toRfc4122()] ?? null) : ($comments[$report->targetId->toRfc4122()] ?? null);
            if ($content instanceof Post || $content instanceof Comment) {
                if ($content->isPublished() && $report->isOpen()) {
                    $actions[] = 'remove';
                }
                if ('removed' === $content->getStatus()->value) {
                    $actions[] = 'restore';
                }
            }
        }
        if ($platform && 'profile' === $report->targetType && isset($users[$report->targetId->toRfc4122()])) {
            $user = $users[$report->targetId->toRfc4122()];
            if (AccountStatus::Suspended === $user->getStatus()) {
                $actions[] = 'reactivate';
            } elseif ($user->isActive() && $report->isOpen()) {
                $actions[] = 'suspend';
            }
        }
        if ($platform && 'comuna' === $report->targetType && isset($comunas[$report->targetId->toRfc4122()])) {
            $comuna = $comunas[$report->targetId->toRfc4122()];
            if ($comuna->isSuspended()) {
                $actions[] = 'reactivate_comuna';
            } elseif ($comuna->isActive() && $report->isOpen()) {
                $actions[] = 'suspend_comuna';
            }
        }

        return array_values(array_unique($actions));
    }

    private function claim(User $actor, ContentReport $report): void
    {
        $this->assertCanClose($actor, $report);
        $this->requireOpen($report);
        $report->status = 'reviewing';
        $report->reviewer = $actor;
        $report->claimedAt = $this->clock->now();
        $this->em->flush();
        $this->audit->record($actor, 'report.claimed', $this->targetUserId($report), 'Análise assumida.', $this->details($report));
    }

    private function dismiss(User $actor, ContentReport $report, string $note): void
    {
        $this->assertCanClose($actor, $report);
        $this->requireOpen($report);
        $this->close($report, 'dismissed', $actor);
        $this->audit->record($actor, 'report.dismissed', $this->targetUserId($report), '' === $note ? 'Sem ação.' : $note, $this->details($report));
    }

    private function remove(User $actor, ContentReport $report, string $note, bool $close): void
    {
        if ($close) {
            $this->assertCanClose($actor, $report);
            $this->requireOpen($report);
        } elseif (!$this->isLocalModerator($actor, $report)) {
            throw ApiProblem::forbidden('/errors/not-comuna-moderator', 'Só a moderação desta comuna pode escalar esta denúncia.');
        }
        $reason = '' === $note ? 'Removido pela moderação.' : $note;
        if ('post' === $report->targetType) {
            $post = $this->em->find(Post::class, $report->targetId);
            if ($post instanceof Post && $post->isPublished()) {
                $this->removePost($actor, $post, $reason);
            }
        } elseif ('comment' === $report->targetType) {
            $comment = $this->em->find(Comment::class, $report->targetId);
            if ($comment instanceof Comment && $comment->isPublished()) {
                $this->removeComment($actor, $comment, $reason);
            }
        } else {
            throw ApiProblem::conflict('/errors/invalid-moderation-action', 'Este alvo não é uma publicação nem um comentário.');
        }
        if ($close) {
            $this->close($report, 'resolved', $actor);
            $this->audit->record($actor, 'report.resolved', $this->targetUserId($report), $reason, $this->details($report) + ['action' => 'remove']);
        }
    }

    private function removeAndEscalate(User $actor, ContentReport $report, string $note): void
    {
        $this->requireOpen($report);
        $this->remove($actor, $report, $note, false);
        $this->markEscalated($actor, $report, $note);
    }

    private function escalate(User $actor, ContentReport $report, string $note): void
    {
        $this->requireOpen($report);
        if (!$this->isLocalModerator($actor, $report) || 'comuna' !== $report->scope || !\in_array($report->targetType, ['post', 'comment'], true)) {
            throw ApiProblem::forbidden('/errors/not-comuna-moderator', 'Só a moderação da comuna pode escalar conteúdo local.');
        }
        $this->markEscalated($actor, $report, $note);
    }

    private function markEscalated(User $actor, ContentReport $report, string $note): void
    {
        $report->escalated = true;
        $report->status = 'pending';
        $report->reviewer = null;
        $report->claimedAt = null;
        $this->em->flush();
        $this->audit->record($actor, 'report.escalated', $this->targetUserId($report), '' === $note ? 'Escalada para a plataforma.' : $note, $this->details($report));
    }

    private function restore(User $actor, ContentReport $report, string $note): void
    {
        $platform = $this->isPlatform($actor);
        if (!$platform && !$this->isLocalModerator($actor, $report)) {
            throw ApiProblem::notFound('Denúncia não encontrada.');
        }
        $reason = '' === $note ? 'Conteúdo restaurado.' : $note;
        $restored = false;
        if ('post' === $report->targetType) {
            $post = $this->em->find(Post::class, $report->targetId);
            if ($post instanceof Post) {
                if (!$platform) {
                    $this->assertLocalCanTouch($actor, $post->getComuna(), $post->getAuthor());
                }
                $restored = $post->restoreByModeration();
            }
        } elseif ('comment' === $report->targetType) {
            $comment = $this->em->find(Comment::class, $report->targetId);
            if ($comment instanceof Comment) {
                if (!$platform) {
                    $this->assertLocalCanTouch($actor, $comment->getPost()->getComuna(), $comment->getAuthor());
                }
                $restored = $comment->restoreByModeration();
            }
        }
        if (!$restored) {
            throw ApiProblem::conflict('/errors/cannot-restore', 'Só dá para restaurar conteúdo removido pela moderação.');
        }
        $this->em->flush();
        $this->audit->record($actor, 'content.restored', $this->targetUserId($report), $reason, $this->details($report));
    }

    private function suspendAccount(User $actor, ContentReport $report, string $note): void
    {
        $this->assertPlatform($actor);
        $this->requireOpen($report);
        $user = $this->accountTarget($report);
        if (AccountStatus::Deleted === $user->getStatus()) {
            throw ApiProblem::conflict('/errors/account-deleted', 'Esta conta já foi excluída.');
        }
        if ($user->isActive()) {
            $user->changeStatus(AccountStatus::Suspended, $this->clock->now());
            $this->sessions->revokeAll($user, 'moderation');
        }
        $this->close($report, 'resolved', $actor);
        $this->audit->record($actor, 'account.suspended', $user->getId(), '' === $note ? 'Conta suspensa.' : $note, $this->details($report));
    }

    private function reactivateAccount(User $actor, ContentReport $report, string $note): void
    {
        $this->assertPlatform($actor);
        $user = $this->accountTarget($report);
        if (AccountStatus::Suspended !== $user->getStatus()) {
            throw ApiProblem::conflict('/errors/account-not-suspended', 'Esta conta não está suspensa.');
        }
        $user->changeStatus(AccountStatus::Active, $this->clock->now());
        if ($report->isOpen()) {
            $this->close($report, 'resolved', $actor);
        } else {
            $this->em->flush();
        }
        $this->audit->record($actor, 'account.reactivated', $user->getId(), '' === $note ? 'Conta reativada.' : $note, $this->details($report));
    }

    private function suspendComuna(User $actor, ContentReport $report, string $note): void
    {
        $this->assertPlatform($actor);
        $this->requireOpen($report);
        $comuna = $this->comunaTarget($report);
        if ($comuna->isArchived()) {
            throw ComunaModerationPolicy::archived();
        }
        if ($comuna->isActive()) {
            $comuna->suspend();
        }
        $this->close($report, 'resolved', $actor);
        $this->audit->record($actor, 'comuna.suspended', $comuna->getOwner()?->getId(), '' === $note ? 'Comuna suspensa.' : $note, $this->details($report) + ['slug' => $comuna->getSlug()]);
    }

    private function reactivateComuna(User $actor, ContentReport $report, string $note): void
    {
        $this->assertPlatform($actor);
        $comuna = $this->comunaTarget($report);
        if (!$comuna->isSuspended()) {
            throw ApiProblem::conflict('/errors/comuna-not-suspended', 'Esta comuna não está suspensa.');
        }
        $comuna->reactivate();
        if ($report->isOpen()) {
            $this->close($report, 'resolved', $actor);
        } else {
            $this->em->flush();
        }
        $this->audit->record($actor, 'comuna.reactivated', $comuna->getOwner()?->getId(), '' === $note ? 'Comuna reativada.' : $note, $this->details($report) + ['slug' => $comuna->getSlug()]);
    }

    private function removePost(User $actor, Post $post, string $reason): void
    {
        $comuna = $post->getComuna();
        if ($this->isPlatform($actor) || null === $comuna) {
            $post->removeByModeration();
            $this->em->flush();
            $this->pins->release($post);
            $this->audit->record($actor, 'platform.post_removed', $post->getAuthor()->getId(), $reason, [
                'post_id' => $post->getId()->toRfc4122(),
                'comuna_id' => $comuna?->getId()->toRfc4122(),
            ]);

            return;
        }
        $this->governance->removePost($post, $actor, $reason);
    }

    private function removeComment(User $actor, Comment $comment, string $reason): void
    {
        $comuna = $comment->getPost()->getComuna();
        if ($this->isPlatform($actor) || null === $comuna) {
            $comment->removeByModeration();
            $this->em->flush();
            $this->audit->record($actor, 'platform.comment_removed', $comment->getAuthor()->getId(), $reason, [
                'comment_id' => $comment->getId()->toRfc4122(),
                'comuna_id' => $comuna?->getId()->toRfc4122(),
            ]);

            return;
        }
        $this->governance->removeComment($comment, $actor, $reason);
    }

    private function assertLocalCanTouch(User $actor, ?Comuna $comuna, User $author): void
    {
        if (null === $comuna || $comuna->isSuspended()) {
            throw ApiProblem::forbidden('/errors/not-comuna-moderator', 'Só a plataforma restaura conteúdo fora de uma comuna ativa.');
        }
        $membership = $this->moderation->assertModerator($comuna, $actor);
        if (!$this->moderation->canRemoveContentBy($comuna, $membership, $author)) {
            throw ApiProblem::forbidden('/errors/cannot-moderate-content', 'Você não pode moderar este conteúdo.', 'O conteúdo de quem é proprietário só sai por decisão do proprietário ou da equipe.');
        }
    }

    private function close(ContentReport $report, string $status, User $actor): void
    {
        $report->status = $status;
        $report->resolvedAt = $this->clock->now();
        $report->reviewer = $actor;
        $this->em->flush();
    }

    private function requireOpen(ContentReport $report): void
    {
        if (!$report->isOpen()) {
            throw ApiProblem::conflict('/errors/report-already-decided', 'Esta denúncia já foi decidida.');
        }
    }

    private function assertCanClose(User $actor, ContentReport $report): void
    {
        if ($this->isPlatform($actor) && ('platform' === $report->scope || $report->escalated || true === $report->comuna?->isSuspended())) {
            return;
        }
        if ($this->isLocalModerator($actor, $report) && !$report->escalated) {
            return;
        }
        throw ApiProblem::forbidden('/errors/not-platform-moderator', 'Esta denúncia não está na sua fila.');
    }

    private function assertCanSee(User $actor, ContentReport $report): void
    {
        if (!\in_array($report->targetType, self::TYPES, true)) {
            throw ApiProblem::notFound('Denúncia não encontrada.');
        }
        if ($this->isPlatform($actor) && ('platform' === $report->scope || $report->escalated || true === $report->comuna?->isSuspended())) {
            return;
        }
        if ($this->isLocalModerator($actor, $report)) {
            return;
        }
        throw ApiProblem::notFound('Denúncia não encontrada.');
    }

    private function isLocalModerator(User $actor, ContentReport $report): bool
    {
        $comuna = $report->comuna;
        if (null === $comuna || $comuna->isSuspended() || 'comuna' !== $report->scope) {
            return false;
        }
        $membership = $this->moderation->permissions($comuna, $this->em->getRepository(\App\Entity\ComunaMembership::class)->findOneBy(['comuna' => $comuna, 'user' => $actor]));

        return $membership['canModerateContent'];
    }

    private function assertPlatform(User $actor): void
    {
        if (!$this->isPlatform($actor)) {
            throw ApiProblem::forbidden('/errors/not-platform-moderator', 'Só a moderação da plataforma pode fazer isso.');
        }
    }

    private function isPlatform(User $actor): bool
    {
        return \in_array($actor->getPlatformRole(), [PlatformRole::PlatformModerator, PlatformRole::Admin], true);
    }

    private function hasOpenReport(User $actor, string $targetType, Uuid $targetId): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ContentReport::class, 'r')
            ->where('r.reporter = :reporter AND r.targetType = :type AND r.targetId = :id AND r.status IN (:open)')
            ->setParameter('reporter', $actor)
            ->setParameter('type', $targetType)
            ->setParameter('id', $targetId, 'uuid')
            ->setParameter('open', ['pending', 'reviewing'])
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    private function find(string $id): ContentReport
    {
        $report = Uuid::isValid($id) ? $this->em->find(ContentReport::class, Uuid::fromString($id)) : null;
        if (!$report instanceof ContentReport) {
            throw ApiProblem::notFound('Denúncia não encontrada.');
        }

        return $report;
    }

    private function lock(string $id): ContentReport
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound('Denúncia não encontrada.');
        }
        $report = $this->em->find(ContentReport::class, Uuid::fromString($id), LockMode::PESSIMISTIC_WRITE);
        if (!$report instanceof ContentReport) {
            throw ApiProblem::notFound('Denúncia não encontrada.');
        }
        $this->em->refresh($report);

        return $report;
    }

    private function accountTarget(ContentReport $report): User
    {
        if ('profile' !== $report->targetType) {
            throw ApiProblem::conflict('/errors/invalid-moderation-action', 'Suspender conta só vale para denúncia de perfil.');
        }
        $user = $this->em->find(User::class, $report->targetId);
        if (!$user instanceof User) {
            throw ApiProblem::conflict('/errors/target-missing', 'A conta denunciada não existe mais.');
        }

        return $user;
    }

    private function comunaTarget(ContentReport $report): Comuna
    {
        if ('comuna' !== $report->targetType) {
            throw ApiProblem::conflict('/errors/invalid-moderation-action', 'Essa ação só vale para denúncia de comuna.');
        }
        $comuna = $this->em->find(Comuna::class, $report->targetId);
        if (!$comuna instanceof Comuna) {
            throw ApiProblem::conflict('/errors/target-missing', 'A comuna denunciada não existe mais.');
        }

        return $comuna;
    }

    private function targetUserId(ContentReport $report): ?Uuid
    {
        if ('profile' === $report->targetType) {
            return $report->targetId;
        }
        if ('post' === $report->targetType) {
            $post = $this->em->find(Post::class, $report->targetId);

            return $post instanceof Post ? $post->getAuthor()->getId() : null;
        }
        if ('comment' === $report->targetType) {
            $comment = $this->em->find(Comment::class, $report->targetId);

            return $comment instanceof Comment ? $comment->getAuthor()->getId() : null;
        }

        return $this->em->find(Comuna::class, $report->targetId)?->getOwner()?->getId();
    }

    /** @return array<string, scalar|null> */
    private function details(ContentReport $report): array
    {
        return [
            'report_id' => $report->id->toRfc4122(),
            'target_type' => $report->targetType,
            'target_id' => $report->targetId->toRfc4122(),
            'comuna_id' => $report->comuna?->getId()->toRfc4122(),
            'escalated' => $report->escalated ? 'true' : 'false',
        ];
    }

    /** @return array{0: list<string>, 1: 'ASC'|'DESC'} */
    private function bucket(string $bucket): array
    {
        return match ($bucket) {
            'open' => [['pending', 'reviewing'], 'ASC'],
            'closed' => [['resolved', 'dismissed', 'removed'], 'DESC'],
            default => throw ApiProblem::badRequest('/errors/invalid-report-bucket', 'Use pendentes ou resolvidas.'),
        };
    }

    private function description(string $text): string
    {
        if (str_contains($text, '<') || str_contains($text, '>')) {
            throw ApiProblem::badRequest('/errors/invalid-report-text', 'Use texto simples, sem HTML.');
        }
        $text = TextNormalizer::normalize($text);
        if (mb_strlen($text) > ContentReport::DESCRIPTION_MAX) {
            throw ApiProblem::badRequest('/errors/invalid-report-text', 'O texto passa de 800 caracteres.');
        }

        return $text;
    }

    /** @return array{id: Uuid, comuna: ?Comuna, snapshot: string, self: bool} */
    private function resolveTarget(User $actor, string $targetType, string $targetId): array
    {
        if (!\in_array($targetType, self::TYPES, true)) {
            throw ApiProblem::badRequest('/errors/invalid-report-target', 'Esse tipo de denúncia não existe.');
        }
        if ('comuna' === $targetType) {
            $comuna = Uuid::isValid($targetId) ? $this->em->find(Comuna::class, Uuid::fromString($targetId)) : $this->comunas->findOneBySlug($targetId);
            if (!$comuna instanceof Comuna) {
                throw ApiProblem::notFound('Comuna não encontrada.');
            }

            return [
                'id' => $comuna->getId(),
                'comuna' => null,
                'snapshot' => $this->excerpt($comuna->getName().' ('.$comuna->getSlug().')'),
                'self' => $comuna->isOwnedBy($actor),
            ];
        }
        if (!Uuid::isValid($targetId)) {
            throw ApiProblem::notFound('Não encontrado.');
        }
        $id = Uuid::fromString($targetId);
        if ('post' === $targetType) {
            $post = $this->policy->findVisiblePost($targetId, $actor);

            return [
                'id' => $post->getId(),
                'comuna' => $post->getComuna(),
                'snapshot' => $this->excerpt($post->getBody()),
                'self' => $post->getAuthor()->getId()->equals($actor->getId()),
            ];
        }
        if ('comment' === $targetType) {
            $comment = $this->em->find(Comment::class, $id) ?? throw ApiProblem::notFound('Comentário não encontrado.');
            $this->policy->findVisiblePost($comment->getPost()->getId()->toRfc4122(), $actor);
            if (!$comment->isPublished() || !$comment->getAuthor()->isActive() || $this->blocks->isBlockedEitherWay($actor, $comment->getAuthor())) {
                throw ApiProblem::notFound('Comentário não encontrado.');
            }

            return [
                'id' => $comment->getId(),
                'comuna' => $comment->getPost()->getComuna(),
                'snapshot' => $this->excerpt($comment->getBody()),
                'self' => $comment->getAuthor()->getId()->equals($actor->getId()),
            ];
        }
        $user = $this->em->find(User::class, $id) ?? throw ApiProblem::notFound('Perfil não encontrado.');
        if (!$user->isActive() || $this->blocks->isBlockedEitherWay($actor, $user)) {
            throw ApiProblem::notFound('Perfil não encontrado.');
        }

        return [
            'id' => $user->getId(),
            'comuna' => null,
            'snapshot' => $this->excerpt($user->getDisplayName().' @'.$user->getHandle()),
            'self' => $user->getId()->equals($actor->getId()),
        ];
    }

    private function excerpt(string $text): string
    {
        $text = TextNormalizer::normalize(trim(strip_tags($text)));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        if (mb_strlen($text) <= ContentReport::SNAPSHOT_MAX) {
            return $text;
        }

        return mb_substr($text, 0, ContentReport::SNAPSHOT_MAX - 1).'…';
    }

    /**
     * @param list<ContentReport> $reports
     *
     * @return array<string, Post>
     */
    private function loadPosts(array $reports): array
    {
        $ids = $this->ids($reports, 'post');

        return [] === $ids ? [] : $this->index($this->em->getRepository(Post::class)->findBy(['id' => $ids]));
    }

    /**
     * @param list<ContentReport> $reports
     *
     * @return array<string, Comment>
     */
    private function loadComments(array $reports): array
    {
        $ids = $this->ids($reports, 'comment');
        if ([] === $ids) {
            return [];
        }
        /** @var list<Comment> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('c', 'a', 'p')
            ->from(Comment::class, 'c')
            ->join('c.author', 'a')
            ->join('c.post', 'p')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $this->index($rows);
    }

    /**
     * @param list<ContentReport> $reports
     *
     * @return array<string, User>
     */
    private function loadUsers(array $reports): array
    {
        $ids = $this->ids($reports, 'profile');

        return [] === $ids ? [] : $this->index($this->em->getRepository(User::class)->findBy(['id' => $ids]));
    }

    /**
     * @param list<ContentReport> $reports
     *
     * @return array<string, Comuna>
     */
    private function loadComunas(array $reports): array
    {
        $ids = $this->ids($reports, 'comuna');

        return [] === $ids ? [] : $this->index($this->em->getRepository(Comuna::class)->findBy(['id' => $ids]));
    }

    /**
     * @param list<ContentReport> $reports
     *
     * @return list<Uuid>
     */
    private function ids(array $reports, string $type): array
    {
        $ids = [];
        foreach ($reports as $report) {
            if ($report->targetType === $type) {
                $ids[] = $report->targetId;
            }
        }

        return $ids;
    }

    /**
     * @template T of object
     *
     * @param list<T> $rows
     *
     * @return array<string, T>
     */
    private function index(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            if (method_exists($row, 'getId')) {
                $id = $row->getId();
                if ($id instanceof Uuid) {
                    $indexed[$id->toRfc4122()] = $row;
                }
            }
        }

        return $indexed;
    }
}
