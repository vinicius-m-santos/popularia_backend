<?php

declare(strict_types=1);

namespace App\Gamification;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Escolhe as missões do dia entre ações que a pessoa ainda consegue cumprir.
 * Não é um recomendador: cada candidata é um EXISTS barato.
 */
final readonly class GamificationMissions
{
    /** @var list<GamificationAction> */
    private const array ORDER = [
        GamificationAction::PollParticipated,
        GamificationAction::EventRsvpPositive,
        GamificationAction::DiscussionParticipated,
        GamificationAction::PostSaved,
        GamificationAction::ComunaJoined,
    ];

    private const string VISIBLE_POST = <<<'SQL'
p.status = 'published'
AND a.status = 'active'
AND NOT EXISTS (
    SELECT 1 FROM user_block b
    WHERE (b.blocker_id = :user AND b.blocked_id = a.id)
       OR (b.blocked_id = :user AND b.blocker_id = a.id)
)
AND (
    (
        p.comuna_id IS NULL
        AND (
            a.is_private = FALSE
            OR a.id = :user
            OR EXISTS (
                SELECT 1 FROM user_follow f
                WHERE f.follower_id = :user AND f.followed_id = a.id AND f.approved = TRUE
            )
        )
    )
    OR EXISTS (
        SELECT 1 FROM comuna c
        WHERE c.id = p.comuna_id
          AND (
              c.visibility = 'public'
              OR EXISTS (
                  SELECT 1 FROM comuna_membership m
                  WHERE m.comuna_id = c.id AND m.user_id = :user
              )
          )
    )
)
SQL;

    private const string MEMBER_POST = <<<'SQL'
p.status = 'published'
AND a.status = 'active'
AND NOT EXISTS (
    SELECT 1 FROM user_block b
    WHERE (b.blocker_id = :user AND b.blocked_id = a.id)
       OR (b.blocked_id = :user AND b.blocker_id = a.id)
)
AND (
    (
        p.comuna_id IS NULL
        AND (
            a.is_private = FALSE
            OR a.id = :user
            OR EXISTS (
                SELECT 1 FROM user_follow f
                WHERE f.follower_id = :user AND f.followed_id = a.id AND f.approved = TRUE
            )
        )
    )
    OR EXISTS (
        SELECT 1 FROM comuna c
        INNER JOIN comuna_membership m ON m.comuna_id = c.id AND m.user_id = :user
        WHERE c.id = p.comuna_id AND c.status = 'active'
    )
)
SQL;

    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Até três ações, giradas pela data para não repetir o mesmo trio todo dia
     * quando há mais de três possíveis. Menos de três só quando não há o que oferecer.
     *
     * @return list<GamificationAction>
     */
    public function select(User $user, string $civilDate): array
    {
        $available = [];
        foreach (self::ORDER as $action) {
            if ($this->available($user, $action)) {
                $available[] = $action;
            }
        }

        return self::rotate($available, $civilDate);
    }

    /**
     * @param list<GamificationAction> $available
     *
     * @return list<GamificationAction>
     */
    public static function rotate(array $available, string $civilDate): array
    {
        $count = \count($available);
        if ($count <= 3) {
            return $available;
        }
        $start = CivilDay::index($civilDate) % $count;
        if ($start < 0) {
            $start += $count;
        }
        $picked = [];
        for ($i = 0; $i < 3; ++$i) {
            $picked[] = $available[($start + $i) % $count];
        }

        return $picked;
    }

    private function available(User $user, GamificationAction $action): bool
    {
        $needsEmail = match ($action) {
            GamificationAction::PollParticipated,
            GamificationAction::DiscussionParticipated,
            GamificationAction::EventRsvpPositive => true,
            GamificationAction::PostSaved,
            GamificationAction::ComunaJoined => false,
        };
        if ($needsEmail && !$user->isEmailVerified()) {
            return false;
        }
        $params = ['user' => $user->getId()->toRfc4122()];
        $sql = match ($action) {
            GamificationAction::PollParticipated => 'SELECT 1 FROM poll pl INNER JOIN post p ON p.id = pl.post_id INNER JOIN app_user a ON a.id = p.author_id WHERE '.self::MEMBER_POST.' AND NOT EXISTS (SELECT 1 FROM poll_vote v WHERE v.poll_id = pl.id AND v.user_id = :user) LIMIT 1',
            GamificationAction::DiscussionParticipated => 'SELECT 1 FROM post p INNER JOIN app_user a ON a.id = p.author_id WHERE '.self::MEMBER_POST." AND NOT EXISTS (SELECT 1 FROM comment cm WHERE cm.post_id = p.id AND cm.author_id = :user AND cm.status = 'published') LIMIT 1",
            GamificationAction::PostSaved => 'SELECT 1 FROM post p INNER JOIN app_user a ON a.id = p.author_id WHERE '.self::VISIBLE_POST.' AND NOT EXISTS (SELECT 1 FROM saved_post s WHERE s.post_id = p.id AND s.user_id = :user) LIMIT 1',
            GamificationAction::ComunaJoined => "SELECT 1 FROM comuna c WHERE c.visibility = 'public' AND c.status = 'active' AND NOT EXISTS (SELECT 1 FROM comuna_membership m WHERE m.comuna_id = c.id AND m.user_id = :user) LIMIT 1",
            GamificationAction::EventRsvpPositive => "SELECT 1 FROM comuna_event e INNER JOIN comuna c ON c.id = e.comuna_id WHERE e.status = 'scheduled' AND e.starts_at > :now AND c.status = 'active' AND (c.visibility = 'public' OR EXISTS (SELECT 1 FROM comuna_membership m WHERE m.comuna_id = c.id AND m.user_id = :user)) AND NOT EXISTS (SELECT 1 FROM event_attendance att WHERE att.event_id = e.id AND att.user_id = :user AND att.status IN ('going', 'maybe')) LIMIT 1",
        };
        if (GamificationAction::EventRsvpPositive === $action) {
            $params['now'] = $this->clock->now()->format('Y-m-d H:i:sP');
        }

        return false !== $this->em->getConnection()->fetchOne($sql, $params);
    }
}
