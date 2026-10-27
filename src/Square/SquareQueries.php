<?php

declare(strict_types=1);

namespace App\Square;

use App\Entity\Square;
use App\Entity\SquareMessage;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Consultas limitadas e em lote: histórico, alterações, presença, previews e unread. */
final readonly class SquareQueries
{
    public function __construct(private EntityManagerInterface $em, private ClockInterface $clock)
    {
    }

    /** Predicado SQL com o mesmo bloqueio bilateral de UserBlockRepository. Alias interno, nunca input. */
    public static function unblocked(string $user): string
    {
        return "NOT EXISTS (SELECT 1 FROM user_block b WHERE (b.blocker_id = :viewer AND b.blocked_id = $user) OR (b.blocked_id = :viewer AND b.blocker_id = $user))";
    }

    /** @return list<SquareMessage> */
    public function messages(Square $square, ?Uuid $before, int $limit, ?int $after = null): array
    {
        $qb = $this->em->createQueryBuilder()->select('m', 'a', 's', 'p', 'pa', 'ps')
            ->from(SquareMessage::class, 'm')->join('m.author', 'a')->leftJoin('m.sticker', 's')
            ->leftJoin('m.replyTo', 'p')->leftJoin('p.author', 'pa')->leftJoin('p.sticker', 'ps')
            ->where('m.square = :square')->setParameter('square', $square)
            ->orderBy(null === $after ? 'm.id' : 'm.revision', null === $after ? 'DESC' : 'ASC')->setMaxResults($limit + 1);
        if (null !== $before) {
            $qb->andWhere('m.id < :before')->setParameter('before', $before, 'uuid');
        }
        if (null !== $after) {
            $qb->andWhere('m.revision > :after')->setParameter('after', $after);
        }

        /** @var list<SquareMessage> */
        return $qb->getQuery()->getResult();
    }

    /** @param list<string> $ids
     * @return list<SquareMessage>
     */
    public function byIds(Square $square, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<SquareMessage> */
        return $this->em->createQueryBuilder()->select('m', 'a', 's', 'p', 'pa', 'ps')->from(SquareMessage::class, 'm')
            ->join('m.author', 'a')->leftJoin('m.sticker', 's')->leftJoin('m.replyTo', 'p')->leftJoin('p.author', 'pa')->leftJoin('p.sticker', 'ps')
            ->where('m.square = :square')->andWhere('m.id IN (:ids)')->setParameter('square', $square)->setParameter('ids', $ids)->getQuery()->getResult();
    }

    /** @param list<string> $ids
     * @return list<SquareMessage>
     */
    public function latestByIds(array $ids): array
    {
        /** @var list<SquareMessage> */
        return $this->em->createQueryBuilder()->select('m', 'a', 'st', 'p', 'pa', 'ps')->from(SquareMessage::class, 'm')->join('m.author', 'a')->leftJoin('m.sticker', 'st')->leftJoin('m.replyTo', 'p')->leftJoin('p.author', 'pa')->leftJoin('p.sticker', 'ps')->where('m.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
    }

    /** @param list<SquareMessage> $messages
     * @return array<string, list<array{emoji: string, count: int, mine: bool}>>
     */
    public function reactions(array $messages, User $viewer): array
    {
        if ([] === $messages) {
            return [];
        }
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT r.message_id, r.emoji, COUNT(*) AS total, BOOL_OR(r.user_id = :viewer) AS mine FROM square_reaction r JOIN app_user u ON u.id = r.user_id WHERE r.message_id IN (:ids) AND u.status = \'active\' AND '.self::unblocked('r.user_id').' GROUP BY r.message_id, r.emoji ORDER BY r.emoji',
            ['viewer' => $viewer->getId()->toRfc4122(), 'ids' => array_map(static fn (SquareMessage $m) => $m->id->toRfc4122(), $messages)],
            ['ids' => ArrayParameterType::STRING],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['message_id']][] = ['emoji' => (string) $row['emoji'], 'count' => (int) $row['total'], 'mine' => true === $row['mine'] || 't' === $row['mine'] || 1 === $row['mine'] || '1' === $row['mine']];
        }

        return $result;
    }

    /** @param list<Square> $squares
     * @return array<string, array{presence: int, unread: int, lastReadId: ?string, lastMessageId: ?string, lastActivityAt: ?string}>
     */
    public function stats(array $squares, User $viewer): array
    {
        if ([] === $squares) {
            return [];
        }
        // Uma consulta, inclusive para listas: nunca uma chamada por praça.
        $visibleMessage = "m.status = 'published' AND a.status = 'active' AND ".self::unblocked('m.author_id');
        $presence = self::unblocked('v.user_id');
        $rows = $this->em->getConnection()->fetchAllAssociative(<<<SQL
            SELECT s.id, mine.last_read_id,
              (SELECT COUNT(*) FROM square_visit v JOIN app_user u ON u.id = v.user_id
                WHERE v.square_id = s.id AND v.seen_at > :expires AND u.status = 'active' AND $presence
                AND (c.visibility = 'public' OR EXISTS (SELECT 1 FROM comuna_membership pm WHERE pm.comuna_id = c.id AND pm.user_id = v.user_id))) AS presence,
              (SELECT COUNT(*) FROM square_message m JOIN app_user a ON a.id = m.author_id
                WHERE m.square_id = s.id AND $visibleMessage AND m.author_id <> :viewer AND (mine.last_read_id IS NULL OR m.id > mine.last_read_id)) AS unread,
              (SELECT m.id FROM square_message m JOIN app_user a ON a.id = m.author_id
                WHERE m.square_id = s.id AND $visibleMessage ORDER BY m.id DESC LIMIT 1) AS last_message_id,
              (SELECT m.created_at FROM square_message m JOIN app_user a ON a.id = m.author_id
                WHERE m.square_id = s.id AND $visibleMessage ORDER BY m.id DESC LIMIT 1) AS last_activity_at
            FROM square s JOIN comuna c ON c.id = s.comuna_id
            LEFT JOIN square_visit mine ON mine.square_id = s.id AND mine.user_id = :viewer
            WHERE s.id IN (:ids) AND (c.visibility = 'public' OR EXISTS (SELECT 1 FROM comuna_membership mm WHERE mm.comuna_id = c.id AND mm.user_id = :viewer))
            SQL,
            ['viewer' => $viewer->getId()->toRfc4122(), 'expires' => $this->clock->now()->modify('-90 seconds')->format('Y-m-d H:i:sP'), 'ids' => array_map(static fn (Square $s) => $s->id->toRfc4122(), $squares)],
            ['ids' => ArrayParameterType::STRING],
        );
        $result = [];
        foreach ($rows as $r) {
            $result[(string) $r['id']] = ['presence' => (int) $r['presence'], 'unread' => (int) $r['unread'], 'lastReadId' => null === $r['last_read_id'] ? null : (string) $r['last_read_id'], 'lastMessageId' => null === $r['last_message_id'] ? null : (string) $r['last_message_id'], 'lastActivityAt' => null === $r['last_activity_at'] ? null : (string) $r['last_activity_at']];
        }

        return $result;
    }

    /** @return list<array{id: string, handle: string, displayName: string}> */
    public function presence(Square $square, User $viewer): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative("SELECT u.id, u.handle, u.display_name FROM square_visit v JOIN app_user u ON u.id = v.user_id JOIN square s ON s.id = v.square_id JOIN comuna c ON c.id = s.comuna_id WHERE v.square_id = :square AND v.seen_at > :expires AND u.status = 'active' AND ".self::unblocked('u.id')." AND (c.visibility = 'public' OR EXISTS (SELECT 1 FROM comuna_membership pm WHERE pm.comuna_id = c.id AND pm.user_id = u.id)) ORDER BY v.seen_at DESC, u.id LIMIT 12", ['square' => $square->id->toRfc4122(), 'viewer' => $viewer->getId()->toRfc4122(), 'expires' => $this->clock->now()->modify('-90 seconds')->format('Y-m-d H:i:sP')]);

        return array_map(static fn (array $r) => ['id' => (string) $r['id'], 'handle' => (string) $r['handle'], 'displayName' => (string) $r['display_name']], $rows);
    }

    public function heartbeat(Square $square, User $viewer): void
    {
        $this->em->getConnection()->executeStatement('INSERT INTO square_visit (id, square_id, user_id, seen_at) VALUES (:id, :square, :viewer, :now) ON CONFLICT (square_id, user_id) DO UPDATE SET seen_at = EXCLUDED.seen_at WHERE square_visit.seen_at IS NULL OR square_visit.seen_at < :previous', ['id' => Uuid::v7()->toRfc4122(), 'square' => $square->id->toRfc4122(), 'viewer' => $viewer->getId()->toRfc4122(), 'now' => $this->clock->now()->format('Y-m-d H:i:sP'), 'previous' => $this->clock->now()->modify('-30 seconds')->format('Y-m-d H:i:sP')]);
    }

    public function read(Square $square, User $viewer, Uuid $messageId): void
    {
        $this->em->getConnection()->executeStatement('INSERT INTO square_visit (id, square_id, user_id, last_read_id) VALUES (:id, :square, :viewer, :message) ON CONFLICT (square_id, user_id) DO UPDATE SET last_read_id = GREATEST(square_visit.last_read_id, EXCLUDED.last_read_id) WHERE square_visit.last_read_id IS NULL OR square_visit.last_read_id < EXCLUDED.last_read_id', ['id' => Uuid::v7()->toRfc4122(), 'square' => $square->id->toRfc4122(), 'viewer' => $viewer->getId()->toRfc4122(), 'message' => $messageId->toRfc4122()]);
    }

    /** @return list<Square> */
    public function list(User $viewer, bool $mine, ?Uuid $before, int $limit): array
    {
        $membership = 'EXISTS (SELECT 1 FROM comuna_membership mm WHERE mm.comuna_id = c.id AND mm.user_id = :viewer)';
        $params = ['viewer' => $viewer->getId()->toRfc4122()];
        if (null !== $before) {
            $params['before'] = $before->toRfc4122();
        }
        if ($mine) {
            $cursor = null === $before ? '' : ' AND s.id < :before';
            $sql = "SELECT s.id FROM square s JOIN comuna c ON c.id = s.comuna_id WHERE $membership $cursor ORDER BY s.id DESC LIMIT $limit";
        } else {
            // Satura presença e atividade: o tamanho histórico da comuna não pesa no ranking.
            $presence = self::unblocked('v.user_id');
            $activity = self::unblocked('m.author_id');
            $params += ['expires' => $this->clock->now()->modify('-90 seconds')->format('Y-m-d H:i:sP'), 'recent' => $this->clock->now()->modify('-1 hour')->format('Y-m-d H:i:sP'), 'interests' => json_encode($viewer->getInterests(), JSON_THROW_ON_ERROR)];
            $cursor = null === $before ? '' : 'WHERE score < COALESCE((SELECT score FROM ranked WHERE id = :before), 0) OR (score = COALESCE((SELECT score FROM ranked WHERE id = :before), 0) AND id < :before)';
            $sql = <<<SQL
                WITH ranked AS (
                    SELECT s.id,
                        LEAST(5, (SELECT COUNT(*) FROM square_visit v JOIN app_user u ON u.id = v.user_id WHERE v.square_id = s.id AND v.seen_at > :expires AND u.status = 'active' AND $presence))
                        + LEAST(4, (SELECT COUNT(*) FROM square_message m JOIN app_user a ON a.id = m.author_id WHERE m.square_id = s.id AND m.created_at > :recent AND m.status = 'published' AND a.status = 'active' AND $activity))
                        + CASE WHEN EXISTS (SELECT 1 FROM jsonb_array_elements_text(c.topics) AS topic(value) WHERE topic.value IN (SELECT jsonb_array_elements_text(CAST(:interests AS jsonb)))) THEN 2 ELSE 0 END AS score
                    FROM square s JOIN comuna c ON c.id = s.comuna_id
                    WHERE c.visibility = 'public' AND c.status = 'active' AND s.status = 'active' AND NOT $membership
                ) SELECT id FROM ranked $cursor ORDER BY score DESC, id DESC LIMIT $limit
                SQL;
        }
        $ids = array_map(static fn (mixed $id): string => (string) $id, $this->em->getConnection()->fetchFirstColumn($sql, $params));
        if ([] === $ids) {
            return [];
        }
        /** @var list<Square> $squares */
        $squares = $this->em->createQueryBuilder()->select('s', 'c')->from(Square::class, 's')->join('s.comuna', 'c')->where('s.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        $positions = array_flip($ids);
        usort($squares, static fn (Square $a, Square $b) => $positions[$a->id->toRfc4122()] <=> $positions[$b->id->toRfc4122()]);

        return $squares;
    }
}
