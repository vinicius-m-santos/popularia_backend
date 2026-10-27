<?php

declare(strict_types=1);

namespace App\Api\Discovery;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Comuna\ComunaViews;
use App\Api\Pagination;
use App\Api\Post\PostViews;
use App\Api\Topic\TopicView;
use App\Discovery\DiscoveryQuery;
use App\Entity\Comuna;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\Topic;
use App\Repository\UserBlockRepository;
use App\Repository\UserFollowRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;

/** @implements ProviderInterface<DiscoveryView> */
final readonly class DiscoveryProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private DiscoveryQuery $queries,
        private ComunaViews $comunas,
        private PostViews $posts,
        private UserFollowRepository $follows,
        private UserBlockRepository $blocks,
        private \App\Library\LibraryQueries $libraryQueries,
        private \App\Library\LibraryViews $libraryViews,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DiscoveryView
    {
        $viewer = $this->currentUser->get();
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query : null;
        $topic = (string) $query?->get('topic', '');
        if ('' !== $topic && null === Topic::tryFrom($topic)) {
            throw ApiProblem::badRequest('/errors/invalid-topic', 'Assunto desconhecido.');
        }
        $topic = '' === $topic ? null : $topic;
        $search = $operation instanceof HttpOperation && '/search' === $operation->getUriTemplate();
        $term = trim((string) $query?->get('q', ''));
        $type = (string) $query?->get('type', 'all');
        if (!\in_array($type, ['all', 'comunas', 'people', 'posts', 'documents'], true) || (null !== $topic && 'people' === $type)) {
            throw ApiProblem::badRequest('/errors/invalid-search-type', 'Filtro de busca inválido.');
        }
        // q vazio permite "Ver todas"; um termo curto nunca dispara uma varredura textual.
        if ($search && '' !== $term && (mb_strlen(ltrim($term, '@')) < 3 || mb_strlen($term) > 100 || !preg_match('/[\pL\pN]{3}/u', $term))) {
            throw ApiProblem::badRequest('/errors/invalid-search-query', 'Use de 3 a 100 caracteres e pelo menos três letras ou números seguidos.');
        }
        $page = 'documents' === $type ? \App\Library\LibraryCursor::fromContext($context) : Pagination::fromContext($context);
        if ($search && 'all' === $type && null !== $page->cursor) {
            throw ApiProblem::badRequest('/errors/invalid-cursor', 'Escolha uma categoria antes de paginar.');
        }
        $limit = $search ? ('all' === $type ? min(3, $page->limit) : $page->limit) : 5;
        $comunas = $people = $posts = $recent = [];
        $next = ['comunas' => null, 'people' => null, 'posts' => null];
        foreach (['comunas', 'people', 'posts'] as $kind) {
            if (($search && 'all' !== $type && $kind !== $type) || ('people' === $kind && null !== $topic)) {
                continue;
            }
            // Na página de assunto mostramos também comunas já conhecidas.
            $recommend = !$search && ('comunas' !== $kind || null === $topic);
            $rows = $this->queries->find($kind, $viewer, $search ? $term : '', $topic, $search ? $page->cursor?->toRfc4122() : null, $limit, $recommend);
            $hasMore = \count($rows) > $limit;
            $rows = \array_slice($rows, 0, $limit);
            $last = end($rows);
            $next[$kind] = $hasMore && false !== $last ? $last->getId()->toRfc4122() : null;
            if ('comunas' === $kind) {
                /** @var list<Comuna> $rows */
                $comunas = $this->comunas->many($rows, $viewer, hiddenUsers: $this->blocks->blockedEitherWayIds($viewer));
            } elseif ('people' === $kind) {
                /** @var list<User> $rows */
                $followed = $this->follows->followedIds($viewer, $rows);
                $people = array_map(static fn (User $u) => new DiscoveryPerson($u->getId()->toRfc4122(), $u->getHandle(), $u->getDisplayName(), isset($followed[$u->getId()->toRfc4122()])), $rows);
            } else {
                /** @var list<Post> $rows */
                $posts = $this->posts->many($rows, $viewer);
            }
        }
        if (!$search && null !== $topic) {
            /** @var list<Post> $rows */
            $rows = $this->queries->find('posts', $viewer, '', $topic, null, 5, recent: true);
            $recent = $this->posts->many(\array_slice($rows, 0, 5), $viewer);
        }

        $documents = [];
        if ($search && in_array($type, ['all', 'documents'], true)) {
            $libraryRequest = new Request(['scope' => 'public', 'q' => $term, 'topic' => $topic ?? '', 'limit' => (string) $limit, 'cursor' => $page->cursor?->toRfc4122() ?? '']);
            $libraryRequest->attributes->set('library_rank', $request->attributes->get('library_rank'));
            [$libraryRows, $libraryNext] = $this->libraryQueries->documents($viewer, $libraryRequest, Pagination::fromContext(['request' => $libraryRequest]));
            $documents = $this->libraryViews->many($libraryRows, $viewer);
            $next['documents'] = $libraryNext;
        }

        return new DiscoveryView(array_map(TopicView::fromTopic(...), Topic::cases()), $comunas, $people, $posts, $recent, $next, $documents);
    }
}
