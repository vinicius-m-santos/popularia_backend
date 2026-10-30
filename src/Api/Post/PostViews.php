<?php

declare(strict_types=1);

namespace App\Api\Post;

use App\Api\Event\EventSummary;
use App\Api\Mention\MentionView;
use App\Api\Profile\AuthorView;
use App\Content\ComunaModerationPolicy;
use App\Content\EventSchedule;
use App\Entity\Comuna;
use App\Entity\ComunaEvent;
use App\Entity\Poll;
use App\Entity\Post;
use App\Entity\User;
use App\Poll\PollPercents;
use App\Repository\ComunaMembershipRepository;
use App\Repository\ContentMentionRepository;
use App\Repository\EventAttendanceRepository;
use App\Repository\PollVoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Monta PostView com o estado de quem vê. "canComment" e "canRemove" são só dicas para a
 * interface e seguem as mesmas regras de ContentPolicy::whyCannotComment e de
 * ComunaModerationPolicy; a decisão que vale é a da operação, que consulta as políticas de novo.
 */
final readonly class PostViews
{
    public function __construct(
        private ComunaMembershipRepository $memberships,
        private ComunaModerationPolicy $moderation,
        private \App\Library\LibraryViews $library,
        private \App\Library\ObjectStorage $storage,
        private PollVoteRepository $votes,
        private \App\Repository\SavedPostRepository $saves,
        private ContentMentionRepository $mentions,
        private \App\Repository\ComunaPinnedPostRepository $pins,
        private EntityManagerInterface $em,
        private EventAttendanceRepository $attendance,
        private ClockInterface $clock,
    ) {
    }

    public function one(Post $post, ?User $viewer): PostView
    {
        return $this->many([$post], $viewer)[0];
    }

    /**
     * @param list<Post> $posts
     *
     * @return list<PostView>
     */
    public function many(array $posts, ?User $viewer): array
    {
        $comunas = array_values(array_filter(array_map(static fn (Post $p) => $p->getComuna(), $posts)));
        $memberOf = null === $viewer ? [] : $this->memberships->findForComunas($viewer, $comunas);

        $documents = array_values(array_filter(array_map(static fn (Post $p) => $p->libraryDocument, $posts)));
        $documentViews = [];
        foreach ($this->library->many($documents, $viewer) as $view) {
            $documentViews[$view->id] = $view;
        }

        $polls = [];
        foreach ($posts as $post) {
            if (null !== $poll = $post->getPoll()) {
                $polls[] = $poll;
            }
        }
        $choices = null === $viewer ? [] : $this->votes->choicesFor($viewer, $polls);
        $saved = null === $viewer ? [] : $this->saves->flagsFor($viewer, $posts);
        $mentions = $this->mentions->groupedByPost($posts);
        $pinned = $this->pins->pinnedPostIds($posts);
        $linked = $this->linkedEvents($posts, $viewer);

        return array_map(function (Post $post) use ($viewer, $memberOf, $documentViews, $choices, $saved, $mentions, $pinned, $linked): PostView {
            $comuna = $post->getComuna();
            $membership = null === $comuna ? null : ($memberOf[$comuna->getId()->toRfc4122()] ?? null);
            $isAuthor = null !== $viewer && $post->getAuthor()->getId()->equals($viewer->getId());
            $canComment = null !== $viewer
                && $viewer->isEmailVerified()
                && (null === $comuna || (null !== $membership && $comuna->isActive()));
            $canRemove = null !== $comuna && $comuna->isActive() && $this->moderation->canRemoveContentBy($comuna, $membership, $post->getAuthor());
            $canPin = null !== $comuna && null !== $membership && $membership->getRole()->canModerate() && $comuna->isActive();

            return new PostView(
                $post->getId()->toRfc4122(),
                $post->getBody(),
                $post->getCreatedAt(),
                AuthorView::fromUser($post->getAuthor()),
                null === $comuna ? null : self::comunaSummary($comuna),
                $post->getCommentCount(),
                ['isAuthor' => $isAuthor, 'canComment' => $canComment, 'canDelete' => $isAuthor, 'canRemove' => $canRemove, 'saved' => isset($saved[$post->getId()->toRfc4122()]), 'canPin' => $canPin, 'pinned' => isset($pinned[$post->getId()->toRfc4122()])],
                $post->getTopic(),
                null === $post->libraryDocument ? null : ($documentViews[$post->libraryDocument->id->toRfc4122()] ?? null),
                null === $post->media ? null : new \App\Api\PostMedia\PostMediaView(
                    $post->media->id->toRfc4122(), $post->media->kind, $post->media->mimeType, $post->media->byteSize,
                    $post->media->width, $post->media->height, $post->media->durationMs,
                    $this->storage->signedUrl($post->media->storageKey, 300, $post->media->mimeType, 'inline'),
                ),
                null === $post->getPoll() ? null : self::poll($post->getPoll(), $choices[$post->getPoll()->getId()->toRfc4122()] ?? null),
                array_map(MentionView::fromMention(...), $mentions[$post->getId()->toRfc4122()] ?? []),
                $linked[$post->getId()->toRfc4122()] ?? null,
            );
        }, $posts);
    }

    /**
     * @param list<Post> $posts
     *
     * @return array<string, EventSummary>
     */
    private function linkedEvents(array $posts, ?User $viewer): array
    {
        if ([] === $posts) {
            return [];
        }
        /** @var list<Post> $rows */
        $rows = $this->em->createQuery('SELECT p, e FROM App\Entity\Post p JOIN p.event e WHERE p.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Post $post) => $post->getId(), $posts))
            ->getResult();
        if ([] === $rows) {
            return [];
        }
        $events = [];
        foreach ($rows as $row) {
            $linked = $row->getEvent();
            if ($linked instanceof ComunaEvent) {
                $events[] = $linked;
            }
        }
        $counts = $this->attendance->counts($events, $viewer);
        $now = $this->clock->now();
        $summaries = [];
        foreach ($rows as $row) {
            $event = $row->getEvent();
            if (!$event instanceof ComunaEvent) {
                continue;
            }
            $id = $event->getId()->toRfc4122();
            $zone = EventSchedule::zone($event->getTimeZone());
            $summaries[$row->getId()->toRfc4122()] = new EventSummary(
                $id,
                $event->getTitle(),
                $event->getFormat()->value,
                $event->getStatus()->value,
                $event->getStartsAt()->setTimezone($zone),
                $event->getTimeZone(),
                $event->getPlaceName(),
                $counts[$id]['going'],
                $counts[$id]['maybe'],
                EventSchedule::isPast($now, $event->getStartsAt(), $event->getEndsAt()),
            );
        }

        return $summaries;
    }

    private static function poll(Poll $poll, ?string $votedOptionId): PollView
    {
        $options = $poll->getOptions();
        $counts = array_map(static fn ($option) => $option->getVoteCount(), $options);
        $percents = PollPercents::of($counts);
        $views = [];
        $total = 0;
        foreach ($options as $index => $option) {
            $id = $option->getId()->toRfc4122();
            $total += $counts[$index];
            $views[] = new PollOptionView($id, $option->getLabel(), $counts[$index], $percents[$index], $id === $votedOptionId);
        }

        return new PollView($poll->getId()->toRfc4122(), $poll->getQuestion(), $total, $votedOptionId, $views);
    }

    /** @return array{id: string, slug: string, name: string, status: string} */
    private static function comunaSummary(Comuna $comuna): array
    {
        return ['id' => $comuna->getId()->toRfc4122(), 'slug' => $comuna->getSlug(), 'name' => $comuna->getName(), 'status' => $comuna->getStatus()->value];
    }
}
