<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Comuna;
use App\Enum\ComunaVisibility;
use App\Notification\EventReminderWorker;
use App\Notification\ExpoPushProvider;
use App\Notification\PushWorker;
use App\Repository\NotificationRepository;
use App\Tests\ApiTestCase;
use App\Tests\EventRequests;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

final class EventNotificationTest extends ApiTestCase
{
    use EventRequests;

    /** @var list<array{url: string, payload: array<mixed>}> */
    private array $pushRequests = [];

    public function testReminderWindowDependsOnHowSoonTheEventIs(): void
    {
        $this->mockTime('2026-11-01T15:00:00+00:00');
        [$ownerToken, $goingToken, $event] = $this->pair('+30 hours');
        $row = $this->requireReminder($event);
        $start = new \DateTimeImmutable($this->eventBody('+30 hours')['startsAt']);
        self::assertSame($start->modify('-24 hours')->getTimestamp(), (new \DateTimeImmutable((string) $row['remind_at']))->getTimestamp());

        [, , $soon] = $this->pair('+5 hours', 'cedo');
        $soonRow = $this->requireReminder($soon);
        $soonStart = new \DateTimeImmutable($this->eventBody('+5 hours')['startsAt']);
        self::assertSame($soonStart->modify('-1 hour')->getTimestamp(), (new \DateTimeImmutable((string) $soonRow['remind_at']))->getTimestamp());

        [, , $late] = $this->pair('+90 minutes', 'ja');
        self::assertFalse($this->reminder($late));
        self::assertNotEmpty($ownerToken);
        self::assertNotEmpty($goingToken);
    }

    public function testOnlyGoingAndMaybeAreRemindedAndRetryDoesNotDuplicate(): void
    {
        $this->mockTime('2026-11-01T15:00:00+00:00');
        $owner = $this->createUser('dona');
        $going = $this->createUser('vai');
        $maybe = $this->createUser('talvez');
        $no = $this->createUser('nao');
        $silent = $this->createUser('mudo');
        $comuna = $this->createComuna('londrina', owner: $owner);
        foreach ([$going, $maybe, $no, $silent] as $user) {
            $this->addMember($comuna, $user);
        }
        $event = $this->request('POST', '/comunas/londrina/events', $this->eventBody('+30 hours'), $this->login('dona@exemplo.org')['accessToken']);
        $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'going'], $this->login('vai@exemplo.org')['accessToken']);
        $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'maybe'], $this->login('talvez@exemplo.org')['accessToken']);
        $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'not_going'], $this->login('nao@exemplo.org')['accessToken']);

        $this->mockTime((new \DateTimeImmutable($event['startsAt']))->modify('-23 hours'));
        self::assertGreaterThan(0, $this->worker()->dispatchDue(20));
        self::assertSame(0, $this->worker()->dispatchDue(20));

        self::assertSame('event_reminder', $this->request('GET', '/me/notifications', token: $this->login('vai@exemplo.org')['accessToken'])['items'][0]['type']);
        self::assertSame('event_reminder', $this->request('GET', '/me/notifications', token: $this->login('talvez@exemplo.org')['accessToken'])['items'][0]['type']);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $this->login('nao@exemplo.org')['accessToken'])['items']);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $this->login('mudo@exemplo.org')['accessToken'])['items']);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'event_reminder'"));
    }

    public function testDateChangeRecalculatesAnUnsentReminderAndCancelledEventsDoNotRemind(): void
    {
        $this->mockTime('2026-11-01T15:00:00+00:00');
        [$ownerToken, $goingToken, $event] = $this->pair('+30 hours');
        $before = (new \DateTimeImmutable((string) $this->requireReminder($event)['remind_at']))->getTimestamp();
        $body = $this->eventBody('+30 hours');
        $body['startsAt'] = $this->eventBody('+10 days')['startsAt'];
        $this->request('PATCH', '/events/'.$event, $body, $ownerToken);
        $this->assertStatus(200);
        $afterRow = $this->requireReminder($event);
        $after = (new \DateTimeImmutable((string) $afterRow['remind_at']))->getTimestamp();
        self::assertNotSame($before, $after);
        self::assertNull($afterRow['sent_at']);

        $this->request('POST', '/events/'.$event.'/cancel', token: $ownerToken);
        $this->assertStatus(200);
        self::assertFalse($this->reminder($event));
        self::assertSame('event_cancelled', $this->request('GET', '/me/notifications', token: $goingToken)['items'][0]['type']);
        $this->mockTime((new \DateTimeImmutable($body['startsAt']))->modify('-23 hours'));
        self::assertSame(0, $this->worker()->dispatchDue(10));
        self::assertSame([], array_values(array_filter(
            $this->request('GET', '/me/notifications', token: $this->login('vailondrina@exemplo.org')['accessToken'])['items'],
            static fn (array $item): bool => 'event_reminder' === $item['type'],
        )));
    }

    public function testPlaceAndLinkChangesNotifyAndLeavingAPrivateComunaDropsTheReminder(): void
    {
        $this->mockTime('2026-11-01T15:00:00+00:00');
        $owner = $this->createUser('dona');
        $going = $this->createUser('vai');
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $owner);
        $this->addMember($comuna, $going);
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $goingToken = $this->login('vai@exemplo.org')['accessToken'];
        $event = $this->request('POST', '/comunas/fechada/events', $this->eventBody('+30 hours'), $ownerToken);
        $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'going'], $goingToken);

        $this->request('PATCH', '/events/'.$event['id'], $this->eventEdit($event, ['placeName' => 'Ginásio']), $ownerToken);
        $this->assertStatus(200);
        self::assertSame('event_place_changed', $this->request('GET', '/me/notifications', token: $goingToken)['items'][0]['type']);

        $current = $this->request('GET', '/events/'.$event['id'], token: $ownerToken);
        $this->request('PATCH', '/events/'.$event['id'], $this->eventEdit($current, ['format' => 'hybrid', 'onlineUrl' => 'https://meet.example.org/sala']), $ownerToken);
        self::assertSame('event_link_changed', $this->request('GET', '/me/notifications', token: $goingToken)['items'][0]['type']);

        $this->request('DELETE', '/comunas/fechada/membership', token: $goingToken);
        $this->assertStatus(204);
        $hidden = $this->request('GET', '/me/notifications', token: $goingToken);
        self::assertSame([], array_values(array_filter($hidden['items'], static fn (array $item): bool => str_starts_with($item['type'], 'event_'))));
        $this->request('GET', '/events/'.$event['id'], token: $goingToken);
        $this->assertStatus(404);

        $this->mockTime((new \DateTimeImmutable($event['startsAt']))->modify('-23 hours'));
        $this->worker()->dispatchDue(10);
        self::assertSame([], array_values(array_filter(
            $this->request('GET', '/me/notifications', token: $this->login('vai@exemplo.org')['accessToken'])['items'],
            static fn (array $item): bool => 'event_reminder' === $item['type'],
        )));
    }

    public function testRemovedMemberIsNotRemindedAndPushStaysGeneric(): void
    {
        $this->mockTime('2026-11-01T15:00:00+00:00');
        $owner = $this->createUser('dona');
        $going = $this->createUser('vai');
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $owner);
        $this->addMember($comuna, $going);
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $goingToken = $this->login('vai@exemplo.org')['accessToken'];
        $event = $this->request('POST', '/comunas/fechada/events', $this->eventBody('+30 hours', override: ['title' => 'TITULO-SECRETO', 'placeName' => 'LOCAL-SECRETO']), $ownerToken);
        $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'going'], $goingToken);
        $this->request('DELETE', '/comunas/fechada/members/vai', token: $ownerToken);
        $this->assertStatus(204);
        $this->mockTime((new \DateTimeImmutable($event['startsAt']))->modify('-23 hours'));
        $this->worker()->dispatchDue(5);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'event_reminder'"));

        $comuna = $this->fresh(Comuna::class, $comuna->getId());
        $again = $this->createUser('fica');
        $this->addMember($comuna, $again);
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $stay = $this->login('fica@exemplo.org')['accessToken'];
        $second = $this->request('POST', '/comunas/fechada/events', $this->eventBody('+40 hours', override: ['title' => 'TITULO-SECRETO']), $ownerToken);
        $this->request('PUT', '/events/'.$second['id'].'/attendance', ['status' => 'going'], $stay);
        $this->register($stay);
        $this->mockTime((new \DateTimeImmutable($second['startsAt']))->modify('-23 hours'));
        $this->worker()->dispatchDue(5);
        $push = $this->pushWorker();
        self::assertSame(1, $push->run()['sent']);
        $body = $this->pushRequests[0]['payload'][0]['body'];
        self::assertSame('Você tem um lembrete de atividade.', $body);
        self::assertStringNotContainsString('TITULO-SECRETO', json_encode($this->pushRequests, \JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('title', $this->pushRequests[0]['payload'][0]['data']);

        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $stay = $this->login('fica@exemplo.org')['accessToken'];
        $this->request('PUT', '/me/notification-preferences', ['pushCategories' => ['follows']], $stay);
        $third = $this->request('POST', '/comunas/fechada/events', $this->eventBody('+50 hours', override: ['title' => 'Outro']), $ownerToken);
        $this->request('PUT', '/events/'.$third['id'].'/attendance', ['status' => 'going'], $stay);
        $this->mockTime((new \DateTimeImmutable($third['startsAt']))->modify('-23 hours'));
        $this->worker()->dispatchDue(5);
        $this->pushRequests = [];
        self::assertSame(0, $this->pushWorker()->run()['sent']);
        $inbox = $this->request('GET', '/me/notifications', token: $this->login('fica@exemplo.org')['accessToken']);
        self::assertNotEmpty(array_filter($inbox['items'], static fn (array $item): bool => 'event_reminder' === $item['type']));
    }

    private function worker(): EventReminderWorker
    {
        return static::getContainer()->get(EventReminderWorker::class);
    }

    public function testSuspendedComunaDoesNotLeakNotificationQuotesOrDispatchReminders(): void
    {
        $this->mockTime('2026-11-01T15:00:00+00:00');
        [$ownerToken, $goingToken, $event] = $this->pair('+30 hours');
        $current = $this->request('GET', '/events/'.$event, token: $ownerToken);
        $this->request('PATCH', '/events/'.$event, $this->eventEdit($current, ['placeName' => 'LOCAL RESTRITO']), $ownerToken);
        self::assertNotEmpty($this->request('GET', '/me/notifications', token: $goingToken)['items']);
        $comuna = $this->em()->getRepository(Comuna::class)->findOneBy(['slug' => 'londrina']);
        self::assertInstanceOf(Comuna::class, $comuna);
        $comuna->suspend();
        $this->em()->flush();
        self::assertSame([], $this->request('GET', '/me/notifications', token: $goingToken)['items']);
        self::assertSame(0, $this->request('GET', '/me/notifications/unread-count', token: $goingToken)['unreadCount']);
        $this->mockTime('2026-11-01T22:00:00+00:00');
        $this->worker()->dispatchDue(10);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'event_reminder'"));
    }

    public function testPrivateRejoinRecreatesMissingReminderWithoutRepeatingSentReminder(): void
    {
        $this->mockTime('2026-11-01T15:00:00+00:00');
        $owner = $this->createUser('dona');
        $going = $this->createUser('vai');
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $owner);
        $this->addMember($comuna, $going);
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $goingToken = $this->login('vai@exemplo.org')['accessToken'];
        $event = $this->request('POST', '/comunas/fechada/events', $this->eventBody('+30 hours'), $ownerToken)['id'];
        $this->request('PUT', '/events/'.$event.'/attendance', ['status' => 'going'], $goingToken);
        $this->request('DELETE', '/comunas/fechada/membership', token: $goingToken);
        $this->mockTime('2026-11-01T22:00:00+00:00');
        $this->worker()->dispatchDue(10);
        self::assertFalse($this->reminder($event));

        // Ainda faltam 23 horas: a aprovação deve reconstruir o lembrete sem mudar RSVP.
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $goingToken = $this->login('vai@exemplo.org')['accessToken'];
        $request = $this->request('PUT', '/comunas/fechada/join-request', token: $goingToken);
        $this->request('POST', '/comunas/fechada/join-requests/'.$request['id'].'/approve', token: $ownerToken);
        $this->assertStatus(200);
        self::assertNull($this->requireReminder($event)['sent_at']);
        $this->mockTime('2026-11-02T20:00:00+00:00');
        $this->worker()->dispatchDue(10);
        self::assertNotNull($this->requireReminder($event)['sent_at']);
        self::assertSame(0, $this->worker()->dispatchDue(10));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'event_reminder'"));
    }

    public function testPublicRejoinKeepsAlreadySentReminder(): void
    {
        $this->mockTime('2026-11-01T15:00:00+00:00');
        [, , $event] = $this->pair('+48 hours');
        $this->mockTime('2026-11-02T16:00:00+00:00');
        self::assertSame(1, $this->worker()->dispatchDue(10));
        $sent = $this->requireReminder($event)['sent_at'];
        self::assertNotNull($sent);
        $token = $this->login('vailondrina@exemplo.org')['accessToken'];
        $this->request('DELETE', '/comunas/londrina/membership', token: $token);
        $this->request('PUT', '/comunas/londrina/membership', token: $token);
        $this->assertStatus(200);
        self::assertSame($sent, $this->requireReminder($event)['sent_at']);
        self::assertSame(0, $this->worker()->dispatchDue(10));
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function pair(string $modify, string $slug = 'londrina'): array
    {
        $owner = $this->createUser('dona'.$slug);
        $going = $this->createUser('vai'.$slug);
        $comuna = $this->createComuna($slug, owner: $owner);
        $this->addMember($comuna, $going);
        $ownerToken = $this->login($owner->getEmail())['accessToken'];
        $goingToken = $this->login($going->getEmail())['accessToken'];
        $event = $this->request('POST', '/comunas/'.$slug.'/events', $this->eventBody($modify), $ownerToken);
        $this->assertStatus(201);
        $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'going'], $goingToken);
        $this->assertStatus(200);

        return [$ownerToken, $goingToken, $event['id']];
    }

    /** @return array<string, mixed>|false */
    private function reminder(string $event): array|false
    {
        $row = $this->em()->getConnection()->fetchAssociative('SELECT remind_at, sent_at, sent_for_starts_at FROM event_reminder WHERE event_id = ?', [$event]);

        return false === $row ? false : $row;
    }

    /** @return array<string, mixed> */
    private function requireReminder(string $event): array
    {
        $row = $this->reminder($event);
        self::assertIsArray($row);

        return $row;
    }

    private function register(string $token): void
    {
        $this->request('PUT', '/me/push-devices/'.Uuid::v4()->toRfc4122(), [
            'token' => 'ExpoPushToken[event-device]',
            'platform' => 'ios',
            'revocationSecret' => '00000000-0000-4000-8000-000000000007',
        ], $token);
        $this->assertStatus(200);
    }

    private function pushWorker(): PushWorker
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $payload = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            $this->pushRequests[] = ['url' => $url, 'payload' => $payload];

            return new MockResponse(json_encode(['data' => array_map(static fn ($message, $i) => ['status' => 'ok', 'id' => 'ticket-'.$i], $payload, array_keys($payload))], \JSON_THROW_ON_ERROR));
        });

        return new PushWorker($this->em()->getConnection(), $this->em(), static::getContainer()->get(NotificationRepository::class), new ExpoPushProvider($http, ''), static::getContainer()->get(ClockInterface::class), new NullLogger());
    }
}
