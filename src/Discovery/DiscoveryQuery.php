<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Entity\Comuna;
use App\Entity\ComunaMembership;
use App\Entity\Post;
use App\Entity\User;
use App\Repository\UserBlockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Busca indexável e descoberta limitada. Visibilidade é aplicada ANTES de limite/paginação.
 * SQL escolhe só IDs; entidades e relações são hidratadas em lote para reutilizar os views.
 */
final readonly class DiscoveryQuery
{
    public function __construct(private EntityManagerInterface $em, private ClockInterface $clock)
    {
    }

    /**
     * @return list<Comuna>|list<Post>|list<User>
     */
    public function find(string $kind, User $viewer, string $query, ?string $topic, ?string $cursor, int $limit, bool $recommend = false, bool $recent = false): array
    {
        $viewerId = $viewer->getId()->toRfc4122();
        $params = ['viewer' => $viewerId];
        $blocked = static fn (string $author): string => "NOT EXISTS (SELECT 1 FROM user_block b WHERE (b.blocker_id = :viewer AND b.blocked_id = $author) OR (b.blocked_id = :viewer AND b.blocker_id = $author))";
        $member = 'EXISTS (SELECT 1 FROM comuna_membership m WHERE m.comuna_id = c.id AND m.user_id = :viewer)';
        $order = '';
        if ('people' === $kind) {
            $from = 'app_user x';
            $where = "x.status = 'active' AND x.id <> :viewer AND ".$blocked('x.id');
            $text = "x.display_name || ' ' || x.handle";
            if ($recommend) {
                $where .= ' AND NOT EXISTS (SELECT 1 FROM user_follow f WHERE f.follower_id = :viewer AND f.followed_id = x.id)';
            }
            $class = User::class;
        } elseif ('comunas' === $kind) {
            $from = 'comuna x';
            $where = "x.status = 'active'";
            $text = "x.name || ' ' || x.description";
            if (null !== $topic) {
                $where .= ' AND x.topics->>0 = :topic';
            }
            if ($recommend) {
                $where .= ' AND NOT EXISTS (SELECT 1 FROM comuna_membership m WHERE m.comuna_id = x.id AND m.user_id = :viewer)';
                $order = 'CASE WHEN CAST(:interests AS jsonb) @> jsonb_build_array(x.topics->>0) THEN 1 ELSE 0 END DESC, x.member_count DESC, ';
                $params['interests'] = json_encode($viewer->getInterests(), \JSON_THROW_ON_ERROR);
            }
            $class = Comuna::class;
        } else {
            $from = 'post x JOIN app_user a ON a.id = x.author_id LEFT JOIN comuna c ON c.id = x.comuna_id';
            $where = "x.status = 'published' AND a.status = 'active' AND ".$blocked('a.id')
                .' AND (c.id IS NOT NULL OR NOT a.is_private OR a.id = :viewer OR EXISTS (SELECT 1 FROM user_follow pf WHERE pf.follower_id = :viewer AND pf.followed_id = a.id AND pf.approved))'
                ." AND (c.id IS NULL OR (c.status = 'active' AND (c.visibility = 'public' OR $member)))";
            $text = 'x.body';
            if (null !== $topic) {
                // Duas origens, sem COALESCE: o índice de cada ramo continua utilizável.
                $where .= ' AND ((c.id IS NULL AND x.topic = :topic) OR c.id IN (SELECT tc.id FROM comuna tc WHERE tc.status = \'active\' AND tc.topics->>0 = :topic))';
            }
            if ($recommend) {
                // Conteúdo público para destaques; busca permite privados só com membership.
                $where .= " AND (c.id IS NULL OR c.visibility = 'public') AND x.created_at >= :cutoff";
                $params['cutoff'] = $this->clock->now()->modify('-14 days')->format('Y-m-d H:i:sP');
                $params['now'] = $this->clock->now()->format('Y-m-d H:i:sP');
                $params['interests'] = json_encode($viewer->getInterests(), \JSON_THROW_ON_ERROR);
                $order = '((1 + LEAST(x.comment_count, 20)) / (1 + GREATEST(0, EXTRACT(EPOCH FROM (CAST(:now AS timestamptz) - x.created_at))) / 86400) * CASE WHEN CAST(:interests AS jsonb) @> jsonb_build_array(CASE WHEN c.id IS NULL THEN x.topic ELSE c.topics->>0 END) THEN 1.5 ELSE 1 END) DESC, ';
            } elseif ($recent) {
                $where .= " AND (c.id IS NULL OR c.visibility = 'public')";
            }
            $class = Post::class;
        }
        if (null !== $topic && 'people' !== $kind) {
            $params['topic'] = $topic;
        }
        if ('' !== $query) {
            $where .= " AND popularia_search_text($text) LIKE popularia_search_text(:query) ESCAPE '!'";
            $params['query'] = '%'.strtr(ltrim($query, '@'), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        }
        if (null !== $cursor) {
            $where .= ' AND x.id < :cursor';
            $params['cursor'] = $cursor;
        }
        // Certos ramos não precisam do viewer; DBAL não aceita parâmetros excedentes.
        $sql = "SELECT x.id FROM $from WHERE $where ORDER BY {$order}x.id DESC LIMIT ".($limit + 1);
        if (!str_contains($sql, ':viewer')) {
            unset($params['viewer']);
        }
        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->executeQuery($sql, $params)->fetchFirstColumn();
        if ([] === $ids) {
            return [];
        }
        $qb = $this->em->createQueryBuilder()->select('e')->from($class, 'e')->where('e.id IN (:ids)')->setParameter('ids', $ids);
        if (Post::class === $class) {
            $qb->addSelect('ld', 'lf', 'lc', 'lu')->leftJoin('e.libraryDocument', 'ld')->leftJoin('ld.file', 'lf')->leftJoin('ld.collection', 'lc')->leftJoin('lf.uploadedBy', 'lu')->addSelect('lou', 'loc')->leftJoin('ld.user', 'lou')->leftJoin('ld.comuna', 'loc');
            $qb->addSelect('author', 'comuna', 'poll', 'pollOption')->join('e.author', 'author')->leftJoin('e.comuna', 'comuna')
                ->leftJoin('e.poll', 'poll')->leftJoin('poll.options', 'pollOption');
            // Entre selecionar IDs e hidratar, uma remoção/revogação pode ter ocorrido.
            // Revalida em lote, na própria consulta que lê o texto (sem N+1).
            $memberOf = sprintf('SELECT IDENTITY(hm.comuna) FROM %s hm WHERE hm.user = :hydrateViewer', ComunaMembership::class);
            $qb->andWhere("e.status = 'published' AND author.status = 'active'")
                ->andWhere('comuna.id IS NOT NULL OR author.isPrivate = false OR author = :hydrateViewer OR EXISTS (SELECT hpf.id FROM \App\Entity\UserFollow hpf WHERE hpf.follower = :hydrateViewer AND hpf.followed = author AND hpf.approved = true)')
                ->andWhere("comuna.id IS NULL OR (comuna.status = 'active' AND (comuna.visibility = 'public' OR comuna.id IN ($memberOf)))")
                ->setParameter('hydrateViewer', $viewer);
            UserBlockRepository::excludeBlocked($qb, 'author', $viewer);
            if ($recommend || $recent) {
                $qb->andWhere("comuna.id IS NULL OR comuna.visibility = 'public'");
            }
        } elseif (Comuna::class === $class) {
            $qb->addSelect('owner', 'rules')->leftJoin('e.owner', 'owner')->leftJoin('e.rules', 'rules')
                ->andWhere("e.status = 'active'");
        } else {
            $qb->andWhere("e.status = 'active'");
            UserBlockRepository::excludeBlocked($qb, 'e', $viewer);
        }
        /** @var list<Comuna>|list<Post>|list<User> $entities */
        $entities = $qb->getQuery()->getResult();
        $positions = array_flip($ids);
        usort($entities, static fn (Comuna|Post|User $a, Comuna|Post|User $b): int => $positions[$a->getId()->toRfc4122()] <=> $positions[$b->getId()->toRfc4122()]);

        return $entities;
    }
}
