<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Notification;
use App\Enum\ComunaVisibility;
use App\Notification\ExpoPushProvider;
use App\Notification\NotificationCreator;
use App\Notification\PushWorker;
use App\Repository\NotificationRepository;
use App\Tests\ApiTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

final class PushNotificationTest extends ApiTestCase
{
    /** @var list<array{url: string, payload: array<mixed>}> */
    private array $pushRequests = [];

    private function register(string $token, string $suffix = 'one'): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->request('PUT', '/me/push-devices/'.$id, ['token' => 'ExpoPushToken[test-device-'.$suffix.']', 'platform' => 'ios', 'revocationSecret' => '00000000-0000-4000-8000-000000000007'], $token);
        $this->assertStatus(200);

        return $id;
    }

    /** @param list<array<string, mixed>>|null $tickets
     * @param array<string, array<string, mixed>> $receipts
     */
    private function worker(?array $tickets = null, array $receipts = [], int $status = 200): PushWorker
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($tickets, $receipts, $status): MockResponse {
            $payload = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            $this->pushRequests[] = ['url' => $url, 'payload' => $payload];
            $data = str_ends_with($url, 'getReceipts') ? $receipts : ($tickets ?? array_map(static fn ($message, $i) => ['status' => 'ok', 'id' => 'ticket-'.$i], $payload, array_keys($payload)));

            return new MockResponse(json_encode(['data' => $data], JSON_THROW_ON_ERROR), ['http_code' => $status]);
        });

        return new PushWorker($this->em()->getConnection(), $this->em(), static::getContainer()->get(NotificationRepository::class), new ExpoPushProvider($http, ''), static::getContainer()->get(ClockInterface::class), new NullLogger());
    }

    /** @return array{actor: string, recipient: string, device: string} */
    private function followFixture(): array
    {
        $this->createUser('ana');
        $this->createUser('bia');
        $actor = $this->login('ana@exemplo.org')['accessToken'];
        $recipient = $this->login('bia@exemplo.org')['accessToken'];
        $device = $this->register($recipient);
        $this->request('PUT', '/users/bia/follow', token: $actor);
        $this->assertStatus(200);

        return compact('actor', 'recipient', 'device');
    }

    public function testMultipleDevicesRegistrationRotationAndOwnership(): void
    {
        $this->createUser('bia');
        $this->createUser('ana');
        $bia = $this->login('bia@exemplo.org')['accessToken'];
        $first = $this->register($bia);
        $second = $this->register($bia, 'two');
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM push_device'));
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $this->request('DELETE', '/me/push-devices/'.$first, token: $ana);
        $this->assertStatus(204);
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT disabled_at FROM push_device WHERE id = ?', [$first]));
        $this->request('PUT', '/me/push-devices/'.$first, ['token' => 'ExpoPushToken[different-token]', 'platform' => 'android', 'revocationSecret' => '00000000-0000-4000-8000-000000000007'], $ana);
        $this->assertStatus(404);
        $this->request('PUT', '/me/push-devices/'.$first, ['token' => 'ExpoPushToken[rotated-device-token]', 'platform' => 'android', 'revocationSecret' => '00000000-0000-4000-8000-000000000007'], $bia);
        $this->assertStatus(200);
        $this->request('DELETE', '/me/push-devices/'.$second, token: $bia);
        $this->assertStatus(204);
        $this->request('DELETE', '/me/push-devices/'.$second, token: $bia);
        $this->assertStatus(204);
        self::assertNotNull($this->em()->getConnection()->fetchOne('SELECT disabled_at FROM push_device WHERE id = ?', [$second]));
        $this->request('PUT', '/me/push-devices/'.$first, ['token' => 'not-a-token', 'platform' => 'web'], $bia);
        $this->assertStatus(422);
        $this->request('PUT', '/me/push-devices/'.$first, ['token' => 'ExpoPushToken[test-device-token]', 'platform' => 'ios', 'revocationSecret' => '00000000-0000-4000-8000-000000000007']);
        $this->assertStatus(401);
    }

    public function testNewSessionTransfersTokenWithoutDuplicateDevices(): void
    {
        $fixture = $this->followFixture();
        $id = $this->register($fixture['actor']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM push_device'));
        self::assertSame($id, $this->em()->getConnection()->fetchOne('SELECT id FROM push_device'));
        self::assertSame(0, $this->worker()->run()['sent']);
    }

    public function testCommittedEventGeneratesOneDeliveryPerDeviceAndSafePayload(): void
    {
        $fixture = $this->followFixture();
        $this->register($fixture['recipient'], 'two');
        $worker = $this->worker();
        $result = $worker->run();
        self::assertSame(1, $result['queued']);
        self::assertSame(2, $result['sent']);
        $payload = $this->pushRequests[0]['payload'];
        self::assertCount(2, $payload);
        self::assertSame(1, $payload[0]['badge']);
        self::assertSame(['notificationId', 'recipientId'], array_keys($payload[0]['data']));
        self::assertStringNotContainsString('ana', $payload[0]['body']);
        self::assertSame(0, $worker->run()['sent']);
        self::assertCount(1, $this->pushRequests);
        $db = $this->em()->getConnection();
        $db->executeStatement("UPDATE push_delivery SET available_at = CURRENT_TIMESTAMP - INTERVAL '1 second'");
        self::assertSame(2, $this->worker(receipts: ['ticket-0' => ['status' => 'ok'], 'ticket-1' => ['status' => 'ok']])->run()['receipts']);
        self::assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM push_delivery WHERE status = 'done'"));
    }

    public function testInvalidTokenTicketAndReceiptDisableOnlyThatDevice(): void
    {
        $fixture = $this->followFixture();
        $second = $this->register($fixture['recipient'], 'two');
        $this->worker([['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']], ['status' => 'ok', 'id' => 'accepted']])->run();
        $db = $this->em()->getConnection();
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM push_device WHERE disabled_at IS NOT NULL'));
        $db->executeStatement("UPDATE push_delivery SET available_at = CURRENT_TIMESTAMP - INTERVAL '1 second'");
        $this->worker(receipts: ['accepted' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]])->run();
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM push_device WHERE disabled_at IS NOT NULL'));
        self::assertNotEmpty($second);
    }

    public function testProviderFailureRetriesWithoutBreakingSocialActionOrDuplicatingOutbox(): void
    {
        $fixture = $this->followFixture();
        self::assertSame(1, $this->worker(status: 503)->run()['sent']);
        $db = $this->em()->getConnection();
        self::assertSame('pending', $db->fetchOne('SELECT status FROM push_delivery'));
        self::assertSame(1, $this->request('GET', '/me/notifications/unread-count', token: $fixture['recipient'])['unreadCount']);
        $db->executeStatement("UPDATE push_delivery SET available_at = CURRENT_TIMESTAMP - INTERVAL '1 second'");
        $this->worker()->run();
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM push_delivery'));
        self::assertSame('ticket', $db->fetchOne('SELECT status FROM push_delivery'));
    }

    public function testReadAndLogoutSuppressPendingPush(): void
    {
        $fixture = $this->followFixture();
        $this->request('PUT', '/me/notifications/read-all', token: $fixture['recipient']);
        self::assertSame(0, $this->worker()->run()['sent']);
        self::assertSame([], $this->pushRequests);
        $this->request('DELETE', '/users/bia/follow', token: $fixture['actor']);
        $this->request('PUT', '/users/bia/follow', token: $fixture['actor']);
        $this->request('POST', '/auth/logout', token: $fixture['recipient']);
        self::assertSame(0, $this->worker()->run()['sent']);
    }

    public function testPrivateComunaAccessRevokedBeforeSendSuppressesPush(): void
    {
        $actorUser = $this->createUser('ana');
        $recipientUser = $this->createUser('bia');
        $owner = $this->createUser('owner');
        $comuna = $this->createComuna('privada', ComunaVisibility::Private, $owner);
        $this->addMember($comuna, $actorUser);
        $this->addMember($comuna, $recipientUser);
        $post = $this->createPost($recipientUser, $comuna);
        $actor = $this->login('ana@exemplo.org')['accessToken'];
        $recipient = $this->login('bia@exemplo.org')['accessToken'];
        $this->register($recipient);
        $this->request('POST', '/posts/'.$post->getId().'/comments', ['body' => 'Privado'], $actor);
        $this->request('DELETE', '/comunas/privada/membership', token: $recipient);
        self::assertSame(0, $this->worker()->run()['sent']);
        self::assertSame([], $this->pushRequests);
    }

    public function testPreferencesArePerUserAndSuppressOnlyPush(): void
    {
        $fixture = $this->followFixture();
        $preferences = $this->request('GET', '/me/notification-preferences', token: $fixture['recipient']);
        self::assertContains('follows', $preferences['pushCategories']);
        $this->request('PUT', '/me/notification-preferences', ['pushCategories' => []], $fixture['recipient']);
        $this->assertStatus(200);
        self::assertSame(0, $this->worker()->run()['sent']);
        self::assertSame(1, $this->request('GET', '/me/notifications/unread-count', token: $fixture['recipient'])['unreadCount']);
        self::assertContains('follows', $this->request('GET', '/me/notification-preferences', token: $fixture['actor'])['pushCategories']);
        $this->request('PUT', '/me/notification-preferences', ['pushCategories' => ['invalid']], $fixture['recipient']);
        $this->assertStatus(422);
    }

    public function testSupportDeduplicatesUndoRedoAndSelfAction(): void
    {
        $user = $this->createUser('bia');
        $this->createUser('ana');
        $post = $this->createPost($user, null);
        $recipient = $this->login('bia@exemplo.org')['accessToken'];
        $actor = $this->login('ana@exemplo.org')['accessToken'];
        $comment = $this->request('POST', '/posts/'.$post->getId().'/comments', ['body' => 'Comentário'], $recipient);
        $uri = '/comments/'.$comment['id'].'/support';
        $this->request('PUT', $uri, token: $recipient);
        self::assertSame(0, $this->request('GET', '/me/notifications/unread-count', token: $recipient)['unreadCount']);
        foreach (range(1, 2) as $_) {
            $this->request('PUT', $uri, token: $actor);
            $this->assertStatus(200);
        }
        $this->request('DELETE', $uri, token: $actor);
        $this->request('PUT', $uri, token: $actor);
        $page = $this->request('GET', '/me/notifications', token: $recipient);
        self::assertCount(1, $page['items']);
        self::assertSame('comment_support', $page['items'][0]['type']);
        self::assertSame(['kind' => 'post', 'id' => $post->getId()->toRfc4122()], $page['items'][0]['target']);
    }

    public function testRevocationCapabilityWorksWithoutSessionAndCannotRevokeNewRegistration(): void
    {
        $fixture = $this->followFixture();
        $uri = '/push-devices/'.$fixture['device'].'/revocation';
        $this->request('POST', $uri, ['revocationSecret' => '00000000-0000-4000-8000-000000000008']);
        $this->assertStatus(204);
        $db = $this->em()->getConnection();
        self::assertNull($db->fetchOne('SELECT disabled_at FROM push_device'));
        $this->request('POST', $uri, ['revocationSecret' => '00000000-0000-4000-8000-000000000007']);
        $this->assertStatus(204);
        self::assertNotNull($db->fetchOne('SELECT disabled_at FROM push_device'));
        $this->request('PUT', '/me/push-devices/'.$fixture['device'], ['token' => 'ExpoPushToken[test-device-one]', 'platform' => 'ios', 'revocationSecret' => '00000000-0000-4000-8000-000000000009'], $fixture['recipient']);
        $this->assertStatus(200);
        $this->request('POST', $uri, ['revocationSecret' => '00000000-0000-4000-8000-000000000007']);
        self::assertNull($db->fetchOne('SELECT disabled_at FROM push_device'));
    }

    public function testLateInvalidReceiptDoesNotDisableRotatedToken(): void
    {
        $fixture = $this->followFixture();
        $this->worker()->run();
        $this->request('PUT', '/me/push-devices/'.$fixture['device'], ['token' => 'ExpoPushToken[rotated-device-token]', 'platform' => 'ios', 'revocationSecret' => '00000000-0000-4000-8000-000000000007'], $fixture['recipient']);
        $this->assertStatus(200);
        $db = $this->em()->getConnection();
        $db->executeStatement("UPDATE push_delivery SET available_at = CURRENT_TIMESTAMP - INTERVAL '1 second'");
        $this->worker(receipts: ['ticket-0' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]])->run();
        self::assertNull($db->fetchOne('SELECT disabled_at FROM push_device'));
    }

    public function testBlockedActorAndExpiredSessionSuppressPush(): void
    {
        $fixture = $this->followFixture();
        $this->request('PUT', '/me/blocks/ana', token: $fixture['recipient']);
        $this->assertStatus(204);
        self::assertSame(0, $this->worker()->run()['sent']);
        $this->request('DELETE', '/me/blocks/ana', token: $fixture['recipient']);
        $this->request('DELETE', '/users/bia/follow', token: $fixture['actor']);
        $this->request('PUT', '/users/bia/follow', token: $fixture['actor']);
        $this->em()->getConnection()->executeStatement("UPDATE refresh_token SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second'");
        self::assertSame(0, $this->worker()->run()['sent']);
    }

    public function testPrivateProfilePermissionIsAppliedToHistoryCountAndPush(): void
    {
        $author = $this->createUser('author');
        $this->createUser('ana');
        $this->createUser('bia');
        $post = $this->createPost($author, null);
        $authorToken = $this->login('author@exemplo.org')['accessToken'];
        $actor = $this->login('ana@exemplo.org')['accessToken'];
        $recipient = $this->login('bia@exemplo.org')['accessToken'];
        $this->register($recipient);
        $comment = $this->request('POST', '/posts/'.$post->getId().'/comments', ['body' => 'Primeiro'], $recipient);
        $this->request('POST', '/posts/'.$post->getId().'/comments', ['body' => 'Resposta', 'parentId' => $comment['id']], $actor);
        self::assertSame(1, $this->request('GET', '/me/notifications/unread-count', token: $recipient)['unreadCount']);
        $this->request('PATCH', '/me', ['isPrivate' => true], $authorToken, 'application/merge-patch+json');
        $this->assertStatus(200);
        self::assertSame(0, $this->request('GET', '/me/notifications/unread-count', token: $recipient)['unreadCount']);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $recipient)['items']);
        self::assertSame(0, $this->worker()->run()['sent']);
    }

    public function testAccountDeletionErasesDevicesPreferencesAndDeliveries(): void
    {
        $fixture = $this->followFixture();
        $this->request('PUT', '/me/notification-preferences', ['pushCategories' => ['follows']], $fixture['recipient']);
        $this->worker()->run();
        $this->request('POST', '/me/account-deletion', ['confirmation' => 'EXCLUIR', 'password' => self::PASSWORD], $fixture['recipient']);
        $this->assertStatus(204);
        foreach (['push_device', 'push_delivery', 'notification_preference'] as $table) {
            self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table));
        }
    }

    public function testReprocessingSameEventIsIdempotentBeforeAndAfterFlush(): void
    {
        $this->followFixture();
        $em = $this->em();
        $notification = $em->getRepository(Notification::class)->findOneBy([]);
        self::assertNotNull($notification);
        $follow = $em->getRepository(\App\Entity\UserFollow::class)->findOneBy([]);
        self::assertNotNull($follow);
        $creator = static::getContainer()->get(NotificationCreator::class);
        $actor = $notification->getActor();
        self::assertNotNull($actor);
        $creator->followed($follow, $actor, $notification->getRecipient());
        $em->flush();
        self::assertSame(1, $em->getRepository(Notification::class)->count([]));
    }
}
