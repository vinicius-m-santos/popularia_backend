<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\ComunaVisibility;
use App\Gamification\GamificationAction;
use App\Gamification\GamificationCatalog;
use App\Gamification\GamificationService;
use App\Gamification\LevelProgress;
use App\Tests\ApiTestCase;
use App\Tests\EventRequests;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

final class GamificationTest extends ApiTestCase
{
    use EventRequests;

    public function testReadingProgressDoesNotStartAStreak(): void
    {
        $user = $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $body = $this->state($token);
        self::assertSame(0, $body['xp']);
        self::assertSame(0, $body['streak']);
        self::assertSame(1, $body['level']);
        self::assertSame(0, $body['currentLevelXp']);
        self::assertSame(100, $body['nextLevelXp']);
        self::assertSame(GamificationCatalog::MISSION, $body['missionBonusXp']);
        self::assertSame(GamificationCatalog::DAY, $body['dayBonusXp']);
        self::assertSame([], $body['missions']);
        self::assertCount(7, $body['achievements']);
        self::assertSame([], $this->ledger($user));
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT last_activity_on FROM gamification_profile WHERE user_id = ?', [$user->getId()->toRfc4122()]));

        $again = $this->state($token);
        self::assertSame($body['missions'], $again['missions']);
        self::assertSame(0, $again['streak']);
    }

    public function testProgressRequiresTheAccount(): void
    {
        $this->request('GET', '/me/gamification');
        $this->assertStatus(401);
    }

    public function testPollSaveDiscussionComunaAndRsvpAwardOnceAndDoNotFarm(): void
    {
        $author = $this->createUser('autora');
        $reader = $this->createUser('leia');
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('aberta', owner: $owner);
        $authorToken = $this->login('autora@exemplo.org')['accessToken'];
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $post = $this->request('POST', '/posts', [
            'body' => 'Qual livro?',
            'poll' => ['question' => 'Qual livro?', 'options' => [['label' => 'Um'], ['label' => 'Dois']]],
        ], $authorToken);
        $this->assertStatus(201);
        $option = $post['poll']['options'][0]['id'];
        $other = $post['poll']['options'][1]['id'];
        $plain = $this->createPost($author, null, 'Outro texto');

        $this->request('PUT', '/posts/'.$post['id'].'/poll/vote', ['optionId' => $option], $token);
        $this->assertStatus(200);
        $this->request('PUT', '/posts/'.$post['id'].'/poll/vote', ['optionId' => $option], $token);
        $this->request('PUT', '/posts/'.$post['id'].'/poll/vote', ['optionId' => $other], $token);
        $this->assertAwardedOnce($reader, GamificationAction::PollParticipated->value, GamificationCatalog::POLL);

        $this->request('PUT', '/posts/'.$plain->getId()->toRfc4122().'/save', token: $token);
        $this->assertStatus(204);
        $this->request('DELETE', '/posts/'.$plain->getId()->toRfc4122().'/save', token: $token);
        $this->request('PUT', '/posts/'.$plain->getId()->toRfc4122().'/save', token: $token);
        $this->assertAwardedOnce($reader, GamificationAction::PostSaved->value, GamificationCatalog::SAVE);

        $first = $this->request('POST', '/posts/'.$plain->getId()->toRfc4122().'/comments', ['body' => 'Primeira fala'], $token);
        $this->assertStatus(201);
        $this->request('POST', '/posts/'.$plain->getId()->toRfc4122().'/comments', ['body' => 'De novo'], $token);
        $this->request('POST', '/posts/'.$plain->getId()->toRfc4122().'/comments', ['body' => 'Resposta', 'parentId' => $first['id']], $token);
        $this->assertAwardedOnce($reader, GamificationAction::DiscussionParticipated->value, GamificationCatalog::DISCUSSION);

        $this->request('PUT', '/comunas/'.$comuna->getSlug().'/membership', token: $token);
        $this->assertStatus(200);
        $this->request('DELETE', '/comunas/'.$comuna->getSlug().'/membership', token: $token);
        $this->request('PUT', '/comunas/'.$comuna->getSlug().'/membership', token: $token);
        $this->assertAwardedOnce($reader, GamificationAction::ComunaJoined->value, GamificationCatalog::COMUNA);

        $event = $this->request('POST', '/comunas/'.$comuna->getSlug().'/events', $this->eventBody(), $ownerToken);
        $this->assertStatus(201);
        foreach (['maybe', 'going', 'not_going', 'going'] as $status) {
            $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => $status], $token);
            $this->assertStatus(200);
        }
        $this->assertAwardedOnce($reader, GamificationAction::EventRsvpPositive->value, GamificationCatalog::EVENT);
        $this->assertBalanced($reader);

        $encoded = json_encode($this->state($token), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($plain->getId()->toRfc4122(), $encoded);
        self::assertStringNotContainsString($option, $encoded);
        self::assertStringNotContainsString('ledger', $encoded);
        self::assertArrayNotHasKey('entries', $this->state($token));
    }

    public function testDiscussionCapsAtThreePostsPerCivilDay(): void
    {
        $author = $this->createUser('autora');
        $reader = $this->createUser('leia');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $posts = [];
        for ($i = 0; $i < 4; ++$i) {
            $posts[] = $this->createPost($author, null, 'Texto '.$i);
        }
        foreach ($posts as $post) {
            $this->request('POST', '/posts/'.$post->getId()->toRfc4122().'/comments', ['body' => 'Fala'], $token);
            $this->assertStatus(201);
        }
        for ($i = 0; $i < 10; ++$i) {
            $this->request('POST', '/posts/'.$posts[0]->getId()->toRfc4122().'/comments', ['body' => 'Mais '.$i], $token);
        }

        $rows = $this->rows($reader, GamificationAction::DiscussionParticipated->value);
        self::assertCount(GamificationCatalog::DISCUSSION_DAILY_CAP, $rows);
        self::assertSame(GamificationCatalog::DISCUSSION * GamificationCatalog::DISCUSSION_DAILY_CAP, array_sum(array_map(static fn (array $row): int => (int) $row['xp'], $rows)));
        self::assertSame(14, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM comment WHERE author_id = ?', [$reader->getId()->toRfc4122()]));
    }

    public function testPrivateJoinAwardsOnlyAfterApproval(): void
    {
        $owner = $this->createUser('dona');
        $reader = $this->createUser('leia');
        $this->createComuna('fechada', ComunaVisibility::Private, $owner);
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];

        $request = $this->request('PUT', '/comunas/fechada/join-request', token: $token);
        $this->assertStatus(200);
        self::assertSame([], $this->rows($reader, GamificationAction::ComunaJoined->value));

        $this->request('POST', '/comunas/fechada/join-requests/'.$request['id'].'/approve', token: $ownerToken);
        $this->assertStatus(200);
        $this->assertAwardedOnce($reader, GamificationAction::ComunaJoined->value, GamificationCatalog::COMUNA);

        $this->request('DELETE', '/comunas/fechada/membership', token: $token);
        $again = $this->request('PUT', '/comunas/fechada/join-request', token: $token);
        $this->request('POST', '/comunas/fechada/join-requests/'.$again['id'].'/approve', token: $ownerToken);
        $this->assertAwardedOnce($reader, GamificationAction::ComunaJoined->value, GamificationCatalog::COMUNA);
    }

    public function testFollowPublishingAndFoundingDoNotAwardBaseXp(): void
    {
        $user = $this->createUser('camila');
        $this->createUser('nara');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $this->request('POST', '/posts', ['body' => 'Uma ideia'], $token);
        $this->assertStatus(201);
        $this->request('PUT', '/users/nara/follow', token: $token);
        $this->assertStatus(200);
        $this->request('POST', '/comunas', [
            'name' => 'Hortas do Bairro',
            'description' => 'Mutirões e trocas de mudas.',
            'visibility' => 'private',
            'topics' => ['clima', 'comun'],
            'rules' => [['title' => 'Respeito sempre', 'body' => 'Sem ataques pessoais.']],
        ], $token);
        $this->assertStatus(201);

        self::assertSame([], $this->ledger($user));
    }

    public function testNotGoingDoesNotAwardUntilAPositiveResponse(): void
    {
        $owner = $this->createUser('dona');
        $reader = $this->createUser('leia');
        $this->createComuna('aberta', owner: $owner);
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $event = $this->request('POST', '/comunas/aberta/events', $this->eventBody(), $ownerToken);

        $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'not_going'], $token);
        $this->assertStatus(200);
        self::assertSame([], $this->rows($reader, GamificationAction::EventRsvpPositive->value));

        $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'maybe'], $token);
        $this->assertAwardedOnce($reader, GamificationAction::EventRsvpPositive->value, GamificationCatalog::EVENT);
    }

    public function testSuspendedAccountKeepsHistoryAndGainsNothing(): void
    {
        $author = $this->createUser('autora');
        $reader = $this->createUser('leia');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $post = $this->createPost($author, null, 'Texto');
        $this->request('PUT', '/posts/'.$post->getId()->toRfc4122().'/save', token: $token);
        $before = \count($this->ledger($reader));
        self::assertGreaterThan(0, $before);

        $fresh = $this->fresh(User::class, $reader->getId());
        $fresh->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();
        static::getContainer()->get(GamificationService::class)->record($fresh, GamificationAction::PostSaved, Uuid::v7(), new \DateTimeImmutable());
        self::assertCount($before, $this->ledger($reader));

        $this->request('PUT', '/posts/'.$post->getId()->toRfc4122().'/save', token: $token);
        $this->assertStatus(403);
        self::assertSame('/errors/account-inactive', $this->request('GET', '/me/gamification', token: $token)['type']);
        self::assertCount($before, $this->ledger($reader));
    }

    public function testLevelsFollowTheProgressiveThresholds(): void
    {
        $user = $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];
        $this->state($token);
        foreach ([0, 99, 100, 249, 250, 449, 450, 699, 700, 2700] as $xp) {
            $this->em()->getConnection()->executeStatement('UPDATE gamification_profile SET xp_total = ? WHERE user_id = ?', [$xp, $user->getId()->toRfc4122()]);
            $body = $this->state($token);
            $expected = LevelProgress::fromXp($xp);
            self::assertSame($expected->level, $body['level'], 'nível de '.$xp);
            self::assertSame($expected->xp, $body['xp']);
            self::assertSame($expected->currentLevelXp, $body['currentLevelXp']);
            self::assertSame($expected->nextLevelXp, $body['nextLevelXp']);
        }
    }

    public function testStreakAdvancesOncePerCivilDayAndSurvivesUtcMidnight(): void
    {
        $author = $this->createUser('autora');
        $reader = $this->createUser('leia');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $posts = [];
        for ($i = 0; $i < 4; ++$i) {
            $posts[] = $this->createPost($author, null, 'Dia '.$i);
        }

        $this->mockTime('2026-06-16T01:30:00+00:00');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $this->save($posts[0], $token);
        $this->mockTime('2026-06-16T02:30:00+00:00');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $this->save($posts[1], $token);
        self::assertSame(1, $this->state($token)['streak']);
        self::assertSame('2026-06-15', $this->activityOn($reader));

        $this->mockTime('2026-06-16T03:30:00+00:00');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $this->save($posts[2], $token);
        self::assertSame(2, $this->state($token)['streak']);
        self::assertSame('2026-06-16', $this->activityOn($reader));

        $this->mockTime('2026-06-18T15:00:00-03:00');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $this->save($posts[3], $token);
        self::assertSame(1, $this->state($token)['streak']);
    }

    public function testFollowOnTheGapDayDoesNotKeepTheStreak(): void
    {
        $author = $this->createUser('autora');
        $this->createUser('nara');
        $reader = $this->createUser('leia');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $first = $this->createPost($author, null, 'Um');
        $second = $this->createPost($author, null, 'Dois');

        $this->mockTime('2026-06-15T12:00:00-03:00');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $this->save($first, $token);
        $this->mockTime('2026-06-16T12:00:00-03:00');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $this->request('PUT', '/users/nara/follow', token: $token);
        $this->assertStatus(200);
        $this->mockTime('2026-06-17T12:00:00-03:00');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $this->save($second, $token);

        self::assertSame(1, $this->state($token)['streak']);
        self::assertSame('2026-06-17', $this->activityOn($reader));
    }

    public function testMissionsStayPutDuringTheDaySkipTheImpossibleAndChangeTomorrow(): void
    {
        $this->mockTime('2026-06-15T15:00:00-03:00');
        $owner = $this->createUser('dona');
        $author = $this->createUser('autora');
        $reader = $this->createUser('leia');
        $this->createComuna('aberta', owner: $owner);
        $this->createPost($author, null, 'Sem enquete');
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $this->request('POST', '/comunas/aberta/events', $this->eventBody(), $ownerToken);

        $first = $this->state($token);
        self::assertCount(3, $first['missions']);
        $actions = array_column($first['missions'], 'action');
        self::assertNotContains(GamificationAction::PollParticipated->value, $actions);
        self::assertSame($actions, array_column($this->state($token)['missions'], 'action'));

        $this->request('POST', '/posts', [
            'body' => 'Agora tem enquete',
            'poll' => ['question' => 'Vamos?', 'options' => [['label' => 'Sim'], ['label' => 'Não']]],
        ], $ownerToken);
        $this->assertStatus(201);
        self::assertSame($actions, array_column($this->state($token)['missions'], 'action'));

        $this->mockTime('2026-06-16T15:00:00-03:00');
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $this->request('POST', '/posts', [
            'body' => 'Enquete do dia seguinte',
            'poll' => ['question' => 'Outra?', 'options' => [['label' => 'A'], ['label' => 'B']]],
        ], $ownerToken);
        $this->assertStatus(201);
        $next = array_column($this->state($token)['missions'], 'action');
        self::assertCount(3, $next);
        self::assertNotSame($actions, $next);
    }

    public function testUnverifiedAccountIsNotOfferedMissionsItCannotFinish(): void
    {
        $author = $this->createUser('autora');
        $owner = $this->createUser('dona');
        $this->createPost($author, null, 'Texto');
        $this->createComuna('aberta', owner: $owner);
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $this->request('POST', '/comunas/aberta/events', $this->eventBody(), $ownerToken);
        $user = $this->createUser('nova', verified: false);

        static::getContainer()->get(GamificationService::class)->prepare($user, new \DateTimeImmutable('2026-06-15T15:00:00-03:00'));
        $actions = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT action FROM gamification_mission WHERE user_id = ? ORDER BY slot',
            [$user->getId()->toRfc4122()],
        );

        self::assertNotContains(GamificationAction::PollParticipated->value, $actions);
        self::assertNotContains(GamificationAction::DiscussionParticipated->value, $actions);
        self::assertNotContains(GamificationAction::EventRsvpPositive->value, $actions);
        self::assertLessThanOrEqual(2, \count($actions));
    }

    public function testMissionAndDayBonusesAreAwardedOnce(): void
    {
        $this->mockTime('2026-06-15T15:00:00-03:00');
        $world = $this->richWorld();
        $token = $world['token'];
        $reader = $world['reader'];
        $missions = $this->state($token)['missions'];
        self::assertCount(3, $missions);

        $this->perform($missions[0]['action'], $token, $world);
        $this->perform($missions[0]['action'], $token, $world);
        $this->assertAwardedOnce($reader, GamificationCatalog::MISSION_ACTION, GamificationCatalog::MISSION);
        $done = array_column(array_filter($this->state($token)['missions'], static fn (array $mission): bool => true === $mission['completed']), 'action');
        self::assertContains($missions[0]['action'], $done);

        $this->perform($missions[1]['action'], $token, $world);
        $this->perform($missions[2]['action'], $token, $world);
        $this->perform($missions[2]['action'], $token, $world);
        $this->assertAwardedOnce($reader, GamificationCatalog::DAY_ACTION, GamificationCatalog::DAY);
        self::assertSame([true, true, true], array_column($this->state($token)['missions'], 'completed'));
        $this->assertBalanced($reader);
    }

    public function testAchievementsUnlockOnceIncludingStreakMilestones(): void
    {
        $this->mockTime('2026-06-15T15:00:00-03:00');
        $author = $this->createUser('autora');
        $reader = $this->createUser('leia');
        $authorToken = $this->login('autora@exemplo.org')['accessToken'];
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $post = $this->request('POST', '/posts', [
            'body' => 'Enquete',
            'poll' => ['question' => 'Qual?', 'options' => [['label' => 'A'], ['label' => 'B']]],
        ], $authorToken);
        $this->request('PUT', '/posts/'.$post['id'].'/poll/vote', ['optionId' => $post['poll']['options'][0]['id']], $token);
        $this->request('PUT', '/posts/'.$post['id'].'/poll/vote', ['optionId' => $post['poll']['options'][1]['id']], $token);
        $this->assertUnlockedOnce($reader, 'primeiro_passo');

        $posts = [];
        for ($day = 0; $day < 7; ++$day) {
            $posts[] = $this->createPost($author, null, 'Sequência '.$day);
        }
        for ($day = 0; $day < 7; ++$day) {
            $this->mockTime((new \DateTimeImmutable('2026-07-01T12:00:00-03:00'))->modify('+'.$day.' days'));
            $token = $this->login('leia@exemplo.org')['accessToken'];
            $this->save($posts[$day], $token);
            if (2 === $day) {
                $this->assertUnlockedOnce($reader, 'constancia');
                self::assertFalse($this->isUnlocked($reader, 'uma_semana'));
            }
        }
        $this->assertUnlockedOnce($reader, 'constancia');
        $this->assertUnlockedOnce($reader, 'uma_semana');
        self::assertSame(7, $this->state($token)['streak']);
    }

    public function testDuplicateLedgerInsertIsRejected(): void
    {
        $author = $this->createUser('autora');
        $reader = $this->createUser('leia');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $post = $this->createPost($author, null, 'Texto');
        $this->save($post, $token);
        $this->assertAwardedOnce($reader, GamificationAction::PostSaved->value, GamificationCatalog::SAVE);

        $db = $this->em()->getConnection();
        $db->beginTransaction();
        try {
            $db->executeStatement(
                'INSERT INTO gamification_entry (id, user_id, action, source_type, source_id, xp, civil_date, created_at) SELECT ?, user_id, action, source_type, source_id, xp, civil_date, created_at FROM gamification_entry WHERE user_id = ? AND action = ?',
                [Uuid::v7()->toRfc4122(), $reader->getId()->toRfc4122(), GamificationAction::PostSaved->value],
            );
            self::fail('A chave única deveria recusar a segunda concessão.');
        } catch (UniqueConstraintViolationException) {
            $db->rollBack();
        }
        $this->assertAwardedOnce($reader, GamificationAction::PostSaved->value, GamificationCatalog::SAVE);
        $this->assertBalanced($reader);
    }

    public function testSameDayActionsDoNotAdvanceTheStreakTwice(): void
    {
        $author = $this->createUser('autora');
        $reader = $this->createUser('leia');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $first = $this->createPost($author, null, 'Um');
        $second = $this->createPost($author, null, 'Dois');
        $this->mockTime('2026-06-15T18:00:00-03:00');
        $this->save($first, $token);
        $this->save($second, $token);

        self::assertSame(1, $this->state($token)['streak']);
        self::assertCount(2, $this->rows($reader, GamificationAction::PostSaved->value));
    }

    public function testAccountDeletionRemovesGamification(): void
    {
        $author = $this->createUser('autora');
        $reader = $this->createUser('leia');
        $token = $this->login('leia@exemplo.org')['accessToken'];
        $this->save($this->createPost($author, null, 'Texto'), $token);
        self::assertNotSame([], $this->ledger($reader));

        $this->request('POST', '/me/account-deletion', ['confirmation' => 'EXCLUIR', 'password' => self::PASSWORD], $token);
        $this->assertStatus(204);
        $id = $reader->getId()->toRfc4122();
        foreach (['gamification_entry', 'gamification_mission', 'gamification_achievement', 'gamification_profile'] as $table) {
            self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE user_id = ?', [$id]));
        }
    }

    /** @return array<string, mixed> */
    private function state(string $token): array
    {
        $body = $this->request('GET', '/me/gamification', token: $token);
        $this->assertStatus(200);

        return $body;
    }

    /** @return list<array<string, mixed>> */
    private function ledger(User $user): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT action, xp, source_type, source_id::text AS source_id FROM gamification_entry WHERE user_id = ? ORDER BY created_at, action',
            [$user->getId()->toRfc4122()],
        );

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function rows(User $user, string $action): array
    {
        return array_values(array_filter($this->ledger($user), static fn (array $row): bool => $row['action'] === $action));
    }

    private function assertAwardedOnce(User $user, string $action, int $xp): void
    {
        $rows = $this->rows($user, $action);
        self::assertCount(1, $rows, $action);
        self::assertSame($xp, (int) $rows[0]['xp']);
    }

    private function assertBalanced(User $user): void
    {
        $id = $user->getId()->toRfc4122();
        $sum = (int) $this->em()->getConnection()->fetchOne('SELECT COALESCE(SUM(xp), 0) FROM gamification_entry WHERE user_id = ?', [$id]);
        $stored = (int) $this->em()->getConnection()->fetchOne('SELECT xp_total FROM gamification_profile WHERE user_id = ?', [$id]);
        self::assertSame($sum, $stored);
    }

    private function activityOn(User $user): string
    {
        $value = $this->em()->getConnection()->fetchOne(
            'SELECT last_activity_on::text FROM gamification_profile WHERE user_id = ?',
            [$user->getId()->toRfc4122()],
        );
        self::assertIsString($value);

        return substr($value, 0, 10);
    }

    private function save(\App\Entity\Post $post, string $token): void
    {
        $this->request('PUT', '/posts/'.$post->getId()->toRfc4122().'/save', token: $token);
        $this->assertStatus(204);
    }

    private function assertUnlockedOnce(User $user, string $code): void
    {
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM gamification_achievement WHERE user_id = ? AND code = ?',
            [$user->getId()->toRfc4122(), $code],
        ));
    }

    private function isUnlocked(User $user, string $code): bool
    {
        return 1 === (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM gamification_achievement WHERE user_id = ? AND code = ?',
            [$user->getId()->toRfc4122(), $code],
        );
    }

    /**
     * @param array{token: string, postId: string, optionId: string, slug: string, eventId: string} $world
     */
    private function perform(string $action, string $token, array $world): void
    {
        match ($action) {
            GamificationAction::PollParticipated->value => $this->request('PUT', '/posts/'.$world['postId'].'/poll/vote', ['optionId' => $world['optionId']], $token),
            GamificationAction::PostSaved->value => $this->request('PUT', '/posts/'.$world['postId'].'/save', token: $token),
            GamificationAction::DiscussionParticipated->value => $this->request('POST', '/posts/'.$world['postId'].'/comments', ['body' => 'Uma fala '.Uuid::v7()->toRfc4122()], $token),
            GamificationAction::ComunaJoined->value => $this->request('PUT', '/comunas/'.$world['slug'].'/membership', token: $token),
            GamificationAction::EventRsvpPositive->value => $this->request('PUT', '/events/'.$world['eventId'].'/attendance', ['status' => 'going'], $token),
            default => self::fail($action),
        };
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 201, 204]);
    }

    /** @return array{token: string, reader: User, postId: string, optionId: string, slug: string, eventId: string} */
    private function richWorld(): array
    {
        $owner = $this->createUser('dona');
        $reader = $this->createUser('leia');
        $this->createComuna('aberta', owner: $owner);
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $post = $this->request('POST', '/posts', [
            'body' => 'Enquete',
            'poll' => ['question' => 'Qual?', 'options' => [['label' => 'A'], ['label' => 'B']]],
        ], $ownerToken);
        $this->assertStatus(201);
        $event = $this->request('POST', '/comunas/aberta/events', $this->eventBody(), $ownerToken);
        $this->assertStatus(201);

        return [
            'token' => $this->login('leia@exemplo.org')['accessToken'],
            'reader' => $reader,
            'postId' => $post['id'],
            'optionId' => $post['poll']['options'][0]['id'],
            'slug' => 'aberta',
            'eventId' => $event['id'],
        ];
    }
}
