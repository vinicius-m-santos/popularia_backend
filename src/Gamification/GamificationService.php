<?php

declare(strict_types=1);

namespace App\Gamification;

use App\Api\ApiProblem;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Única porta de XP, sequência, missão e conquista.
 *
 * O total de XP fica na linha da conta e só cresce. Cada concessão é uma linha
 * em gamification_entry com chave única (conta, ação, tipo, origem). Repetir a
 * mesma origem, desfazer e refazer, ou duas requisições ao mesmo tempo não
 * somam de novo: a linha da conta é travada e o conflito não altera o total.
 *
 * A sequência só anda quando essa inserção é nova e a ação é de participação.
 * Abrir o app, entrar ou rolar o feed não chama este serviço. Missão e o bônus
 * do trio também não alongam a sequência.
 *
 * Conta suspensa ou excluída não ganha nada. O histórico que já existe fica.
 */
final readonly class GamificationService
{
    private const string DAY_NAMESPACE = '6f0c2e1a-7b4d-5e3a-9c1f-8a2b5d6e7f80';

    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private GamificationMissions $missions,
    ) {
    }

    /** Garante o perfil e as missões do dia, sem conceder XP. Chamar antes da mutação. */
    public function prepare(User $user, \DateTimeImmutable $now): void
    {
        if (!$user->isActive()) {
            return;
        }
        $this->em->getConnection()->transactional(function () use ($user, $now): void {
            $this->lock($user, $now);
            $this->ensureMissions($user, $now);
        });
    }

    /** Registra uma participação nova. Idempotente por origem. */
    public function record(User $user, GamificationAction $action, Uuid $sourceId, \DateTimeImmutable $now): void
    {
        if (!$user->isActive()) {
            return;
        }
        $this->em->getConnection()->transactional(function () use ($user, $action, $sourceId, $now): void {
            $profile = $this->lock($user, $now);
            $day = CivilDay::date($now);
            $this->ensureMissions($user, $now);
            if (GamificationAction::DiscussionParticipated === $action && $this->discussionAwards($user, $day) >= GamificationCatalog::DISCUSSION_DAILY_CAP) {
                return;
            }
            if (!$this->grant($user, $action->value, $action->sourceType(), $sourceId->toRfc4122(), GamificationCatalog::xp($action), $day, $now)) {
                return;
            }
            $streak = $this->advanceStreak($user, $profile['streak'], $profile['last'], $day, $now);
            $this->unlockFirst($user, $action, $now);
            $this->unlockStreak($user, $streak, $now);
            $this->completeMission($user, $action, $day, $now);
        });
    }

    public function view(User $user): GamificationSnapshot
    {
        if (!$user->isActive()) {
            throw ApiProblem::accountInactive();
        }
        $now = $this->clock->now();
        $this->prepare($user, $now);

        return $this->assemble($user, $now);
    }

    /** @return array{streak: int, last: ?string} */
    private function lock(User $user, \DateTimeImmutable $now): array
    {
        $db = $this->em->getConnection();
        $id = $user->getId()->toRfc4122();
        $db->executeStatement(
            'INSERT INTO gamification_profile (user_id, xp_total, streak_current, last_activity_on, updated_at) VALUES (:user, 0, 0, NULL, :at) ON CONFLICT (user_id) DO NOTHING',
            ['user' => $id, 'at' => $this->stamp($now)],
        );
        $row = $db->fetchAssociative(
            'SELECT streak_current, last_activity_on::text AS last_activity_on FROM gamification_profile WHERE user_id = :user FOR UPDATE',
            ['user' => $id],
        );
        if (!\is_array($row)) {
            throw new \RuntimeException('Perfil de gamificação ausente.');
        }

        return [
            'streak' => (int) $row['streak_current'],
            'last' => \is_string($row['last_activity_on']) ? substr($row['last_activity_on'], 0, 10) : null,
        ];
    }

    private function ensureMissions(User $user, \DateTimeImmutable $now): void
    {
        $db = $this->em->getConnection();
        $day = CivilDay::date($now);
        $id = $user->getId()->toRfc4122();
        $existing = $db->fetchFirstColumn(
            'SELECT action FROM gamification_mission WHERE user_id = :user AND civil_date = :day ORDER BY slot',
            ['user' => $id, 'day' => $day],
        );
        if ([] !== $existing) {
            return;
        }
        $slot = 1;
        foreach ($this->missions->select($user, $day) as $action) {
            $db->executeStatement(
                'INSERT INTO gamification_mission (id, user_id, civil_date, slot, action, completed_at) VALUES (:id, :user, :day, :slot, :action, NULL) ON CONFLICT (user_id, civil_date, slot) DO NOTHING',
                [
                    'id' => Uuid::v7()->toRfc4122(),
                    'user' => $id,
                    'day' => $day,
                    'slot' => $slot,
                    'action' => $action->value,
                ],
            );
            ++$slot;
        }
    }

    private function discussionAwards(User $user, string $day): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM gamification_entry WHERE user_id = :user AND action = :action AND civil_date = :day',
            ['user' => $user->getId()->toRfc4122(), 'action' => GamificationAction::DiscussionParticipated->value, 'day' => $day],
        );
    }

    private function grant(User $user, string $action, string $sourceType, string $sourceId, int $xp, string $day, \DateTimeImmutable $now): bool
    {
        $db = $this->em->getConnection();
        $inserted = $db->fetchOne(
            'INSERT INTO gamification_entry (id, user_id, action, source_type, source_id, xp, civil_date, created_at) VALUES (:id, :user, :action, :source_type, :source_id, :xp, :day, :at) ON CONFLICT (user_id, action, source_type, source_id) DO NOTHING RETURNING id',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'user' => $user->getId()->toRfc4122(),
                'action' => $action,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'xp' => $xp,
                'day' => $day,
                'at' => $this->stamp($now),
            ],
        );
        if (!\is_string($inserted) || '' === $inserted) {
            return false;
        }
        $db->executeStatement(
            'UPDATE gamification_profile SET xp_total = xp_total + :xp, updated_at = :at WHERE user_id = :user',
            ['xp' => $xp, 'at' => $this->stamp($now), 'user' => $user->getId()->toRfc4122()],
        );

        return true;
    }

    private function advanceStreak(User $user, int $current, ?string $last, string $day, \DateTimeImmutable $now): int
    {
        if ($last === $day) {
            $streak = $current;
        } elseif (null !== $last && $last === CivilDay::previous($day)) {
            $streak = $current + 1;
        } else {
            $streak = 1;
        }
        $this->em->getConnection()->executeStatement(
            'UPDATE gamification_profile SET streak_current = :streak, last_activity_on = :day, updated_at = :at WHERE user_id = :user',
            ['streak' => $streak, 'day' => $day, 'at' => $this->stamp($now), 'user' => $user->getId()->toRfc4122()],
        );

        return $streak;
    }

    private function unlockFirst(User $user, GamificationAction $action, \DateTimeImmutable $now): void
    {
        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM gamification_entry WHERE user_id = :user AND action = :action',
            ['user' => $user->getId()->toRfc4122(), 'action' => $action->value],
        );
        if (1 === $count) {
            $this->unlock($user, $action->achievement(), $now);
        }
    }

    private function unlockStreak(User $user, int $streak, \DateTimeImmutable $now): void
    {
        if ($streak >= 3) {
            $this->unlock($user, AchievementCode::Constancia, $now);
        }
        if ($streak >= 7) {
            $this->unlock($user, AchievementCode::UmaSemana, $now);
        }
    }

    private function unlock(User $user, AchievementCode $code, \DateTimeImmutable $now): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO gamification_achievement (user_id, code, unlocked_at) VALUES (:user, :code, :at) ON CONFLICT (user_id, code) DO NOTHING',
            ['user' => $user->getId()->toRfc4122(), 'code' => $code->value, 'at' => $this->stamp($now)],
        );
    }

    private function completeMission(User $user, GamificationAction $action, string $day, \DateTimeImmutable $now): void
    {
        $db = $this->em->getConnection();
        $missionId = $db->fetchOne(
            'UPDATE gamification_mission SET completed_at = :at WHERE user_id = :user AND civil_date = :day AND action = :action AND completed_at IS NULL RETURNING id',
            ['at' => $this->stamp($now), 'user' => $user->getId()->toRfc4122(), 'day' => $day, 'action' => $action->value],
        );
        if (!\is_string($missionId) || '' === $missionId) {
            return;
        }
        $this->grant($user, GamificationCatalog::MISSION_ACTION, 'mission', $missionId, GamificationCatalog::MISSION, $day, $now);
        $done = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gamification_mission WHERE user_id = :user AND civil_date = :day AND completed_at IS NOT NULL',
            ['user' => $user->getId()->toRfc4122(), 'day' => $day],
        );
        if ($done >= 3) {
            $this->grant($user, GamificationCatalog::DAY_ACTION, 'civil_day', $this->daySource($user, $day), GamificationCatalog::DAY, $day, $now);
        }
    }

    private function daySource(User $user, string $day): string
    {
        return Uuid::v5(Uuid::fromString(self::DAY_NAMESPACE), $user->getId()->toRfc4122().'|'.$day)->toRfc4122();
    }

    private function assemble(User $user, \DateTimeImmutable $now): GamificationSnapshot
    {
        $db = $this->em->getConnection();
        $id = $user->getId()->toRfc4122();
        $day = CivilDay::date($now);
        $xp = (int) $db->fetchOne('SELECT xp_total FROM gamification_profile WHERE user_id = :user', ['user' => $id]);
        $streak = (int) $db->fetchOne('SELECT streak_current FROM gamification_profile WHERE user_id = :user', ['user' => $id]);
        $missions = [];
        foreach ($db->fetchAllAssociative(
            'SELECT action, completed_at FROM gamification_mission WHERE user_id = :user AND civil_date = :day ORDER BY slot',
            ['user' => $id, 'day' => $day],
        ) as $row) {
            $action = GamificationAction::tryFrom((string) $row['action']);
            if (null === $action) {
                continue;
            }
            $missions[] = ['action' => $action, 'completed' => null !== $row['completed_at']];
        }
        $unlocked = [];
        foreach ($db->fetchAllAssociative(
            'SELECT code, unlocked_at FROM gamification_achievement WHERE user_id = :user',
            ['user' => $id],
        ) as $row) {
            $at = $this->instant($row['unlocked_at']);
            if (null !== $at) {
                $unlocked[(string) $row['code']] = $at;
            }
        }

        return new GamificationSnapshot($id, LevelProgress::fromXp($xp), $streak, $missions, $unlocked);
    }

    private function instant(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (\is_string($value) && '' !== $value) {
            return new \DateTimeImmutable($value);
        }

        return null;
    }

    private function stamp(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d H:i:sP');
    }
}
