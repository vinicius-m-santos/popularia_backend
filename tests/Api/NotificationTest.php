<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Notification;
use App\Entity\UserFollow;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Enum\NotificationType;
use App\Notification\NotificationCreator;
use App\Tests\ApiTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class NotificationTest extends ApiTestCase
{
    public function testFollowPersistsOnceAndOnlyNotifiesTheTarget(): void
    {
        $this->createUser('ana');
        $this->createUser('bia');
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $bia = $this->login('bia@exemplo.org')['accessToken'];
        foreach (range(1, 2) as $_) {
            $result = $this->request('PUT', '/users/bia/follow', token: $ana);
            $this->assertStatus(200);
            self::assertTrue($result['following']);
        }
        self::assertTrue($this->request('GET', '/users/bia/follow', token: $ana)['following']);
        $page = $this->request('GET', '/me/notifications', token: $bia);
        $this->assertStatus(200);
        self::assertCount(1, $page['items']);
        self::assertSame('follow', $page['items'][0]['type']);
        self::assertSame('ana', $page['items'][0]['actor']['handle']);
        self::assertSame(['kind' => 'profile', 'id' => 'ana'], $page['items'][0]['target']);
        self::assertNull($page['items'][0]['readAt']);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $ana)['items']);
        self::assertSame(1, $this->em()->getRepository(UserFollow::class)->count([]));

        $this->request('PUT', '/users/ana/follow', token: $ana);
        $this->assertStatus(400);
        $this->request('DELETE', '/users/bia/follow', token: $ana);
        $this->assertStatus(204);
        $this->request('DELETE', '/users/bia/follow', token: $ana);
        $this->assertStatus(204);
        self::assertFalse($this->request('GET', '/users/bia/follow', token: $ana)['following']);
    }

    public function testCommentReplyAndSelfActionsDoNotDuplicateRecipients(): void
    {
        $biaUser = $this->createUser('bia');
        $this->createUser('ana');
        $this->createUser('carlos');
        $post = $this->createPost($biaUser, null);
        $bia = $this->login('bia@exemplo.org')['accessToken'];
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $carlos = $this->login('carlos@exemplo.org')['accessToken'];
        $uri = '/posts/'.$post->getId()->toRfc4122().'/comments';

        $own = $this->request('POST', $uri, ['body' => 'Meu comentário'], $bia);
        self::assertSame(0, $this->request('GET', '/me/notifications/unread-count', token: $bia)['unreadCount']);
        $comment = $this->request('POST', $uri, ['body' => 'Vamos conversar'], $ana);
        $this->assertStatus(201);
        self::assertSame(1, $this->request('GET', '/me/notifications/unread-count', token: $bia)['unreadCount']);
        $this->request('POST', $uri, ['body' => 'Resposta direta', 'parentId' => $own['id']], $ana);
        $this->assertStatus(201);
        $items = $this->request('GET', '/me/notifications', token: $bia)['items'];
        self::assertSame(['reply', 'comment'], array_column($items, 'type'));
        self::assertSame('Resposta direta', $items[0]['quote']);

        // Different recipients: the comment author receives a reply; the post author a comment.
        $this->request('POST', $uri, ['body' => 'Para Ana', 'parentId' => $comment['id']], $carlos);
        self::assertSame('reply', $this->request('GET', '/me/notifications', token: $ana)['items'][0]['type']);
        self::assertSame(3, $this->request('GET', '/me/notifications/unread-count', token: $bia)['unreadCount']);
        $before = $this->em()->getRepository(Notification::class)->count([]);
        $this->request('POST', $uri, ['body' => '', 'parentId' => $comment['id']], $carlos);
        $this->assertStatus(422);
        self::assertSame($before, $this->em()->getRepository(Notification::class)->count([]));
        self::assertSame([], $this->request('GET', '/me/notifications', token: $carlos)['items']);
    }

    public function testReadStateCountAndReadAllArePersistentAndPrivate(): void
    {
        $this->createUser('ana');
        $this->createUser('bia');
        $this->createUser('carlos');
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $bia = $this->login('bia@exemplo.org')['accessToken'];
        $carlos = $this->login('carlos@exemplo.org')['accessToken'];
        $this->request('PUT', '/users/bia/follow', token: $ana);
        $this->request('PUT', '/users/bia/follow', token: $carlos);
        $this->request('PUT', '/users/ana/follow', token: $bia);
        $id = $this->request('GET', '/me/notifications', token: $bia)['items'][0]['id'];
        foreach (['GET', 'PUT'] as $method) {
            $this->request($method, '/me/notifications/'.$id.('PUT' === $method ? '/read' : ''), token: $ana);
            $this->assertStatus(404);
        }
        self::assertSame(2, $this->request('GET', '/me/notifications/unread-count', token: $bia)['unreadCount']);
        $first = $this->request('PUT', '/me/notifications/'.$id.'/read', token: $bia);
        $this->assertStatus(200);
        self::assertNotNull($first['readAt']);
        self::assertSame($first['readAt'], $this->request('PUT', '/me/notifications/'.$id.'/read', token: $bia)['readAt']);
        self::assertSame(1, $this->request('GET', '/me/notifications/unread-count', token: $bia)['unreadCount']);
        self::assertSame(0, $this->request('PUT', '/me/notifications/read-all', token: $bia)['unreadCount']);
        self::assertSame(0, $this->request('GET', '/me/notifications/unread-count', token: $bia)['unreadCount']);
        self::assertSame(1, $this->request('GET', '/me/notifications/unread-count', token: $ana)['unreadCount']);
        foreach ($this->request('GET', '/me/notifications', token: $bia)['items'] as $item) { self::assertNotNull($item['readAt']); }
        foreach (['/me/notifications', '/me/notifications/unread-count'] as $uri) {
            $this->request('GET', $uri);
            $this->assertStatus(401);
        }
        $this->request('PUT', '/me/notifications/read-all');
        $this->assertStatus(401);
    }

    public function testPaginationIsBoundedStableAndNewestFirst(): void
    {
        $recipient = $this->createUser('bia');
        $actor = $this->createUser('ana');
        for ($i = 0; $i < 24; ++$i) {
            $this->em()->persist(new Notification($recipient, $actor, NotificationType::Follow, 'event-'.$i, new \DateTimeImmutable()));
        }
        $this->em()->flush();
        $token = $this->login('bia@exemplo.org')['accessToken'];
        $default = $this->request('GET', '/me/notifications', token: $token);
        self::assertCount(20, $default['items']);
        self::assertNotNull($default['nextCursor']);
        $first = $this->request('GET', '/me/notifications?limit=13', token: $token);
        $second = $this->request('GET', '/me/notifications?limit=13&cursor='.$first['nextCursor'], token: $token);
        self::assertCount(13, $first['items']);
        self::assertCount(11, $second['items']);
        self::assertNull($second['nextCursor']);
        $ids = array_merge(array_column($first['items'], 'id'), array_column($second['items'], 'id'));
        self::assertCount(24, array_unique($ids));
        $ordered = $ids; rsort($ordered); self::assertSame($ordered, $ids);
        foreach (['limit=51', 'limit=0', 'cursor=invalid'] as $query) {
            $this->request('GET', '/me/notifications?'.$query, token: $token);
            $this->assertStatus(400);
        }
    }

    public function testPrivateRequestsNotifyOnlyCurrentModeratorsAndDecisionNotifiesRequester(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $owner);
        $moderator = $this->createUser('modera');
        $this->addMember($comuna, $moderator, ComunaRole::Moderator);
        $member = $this->createUser('membro');
        $this->addMember($comuna, $member);
        $this->createUser('ana');
        $tokens = [];
        foreach (['dona', 'modera', 'membro', 'ana'] as $handle) { $tokens[$handle] = $this->login($handle.'@exemplo.org')['accessToken']; }
        $pending = $this->request('PUT', '/comunas/fechada/join-request', token: $tokens['ana']);
        $this->request('PUT', '/comunas/fechada/join-request', token: $tokens['ana']);
        foreach (['dona', 'modera'] as $handle) {
            $items = $this->request('GET', '/me/notifications', token: $tokens[$handle])['items'];
            self::assertCount(1, $items);
            self::assertSame('join_requested', $items[0]['type']);
            self::assertSame(['kind' => 'join_requests', 'id' => 'fechada'], $items[0]['target']);
        }
        self::assertSame([], $this->request('GET', '/me/notifications', token: $tokens['membro'])['items']);
        $this->request('POST', '/comunas/fechada/join-requests/'.$pending['id'].'/approve', token: $tokens['modera']);
        $this->assertStatus(200);
        $items = $this->request('GET', '/me/notifications', token: $tokens['ana'])['items'];
        self::assertCount(1, $items);
        self::assertSame('join_approved', $items[0]['type']);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $tokens['dona'])['items']);
        $this->request('POST', '/comunas/fechada/join-requests/'.$pending['id'].'/approve', token: $tokens['modera']);
        $this->assertStatus(409);
        self::assertSame(1, $this->request('GET', '/me/notifications/unread-count', token: $tokens['ana'])['unreadCount']);
        $this->request('DELETE', '/comunas/fechada/membership', token: $tokens['ana']);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $tokens['ana'])['items']);
    }

    public function testRejectedRequestOpensOnlyTheExistingPrivatePreview(): void
    {
        $this->createComuna('fechada', ComunaVisibility::Private, $this->createUser('dona'));
        $this->createUser('ana');
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $owner = $this->login('dona@exemplo.org')['accessToken'];
        $pending = $this->request('PUT', '/comunas/fechada/join-request', token: $ana);
        $this->request('POST', '/comunas/fechada/join-requests/'.$pending['id'].'/reject', token: $owner);
        $items = $this->request('GET', '/me/notifications', token: $ana)['items'];
        self::assertSame('join_rejected', $items[0]['type']);
        self::assertSame(['kind' => 'comuna', 'id' => 'fechada'], $items[0]['target']);
        $this->request('GET', '/comunas/fechada', token: $ana);
        $this->assertStatus(200);
        $this->request('GET', '/comunas/fechada/posts', token: $ana);
        $this->assertStatus(403);
    }

    public function testLostModerationCancelledRequestAndArchiveHideActionableNotifications(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $owner);
        $moderator = $this->createUser('modera');
        $this->addMember($comuna, $moderator, ComunaRole::Moderator);
        $this->createUser('ana');
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $modToken = $this->login('modera@exemplo.org')['accessToken'];
        $this->request('PUT', '/comunas/fechada/join-request', token: $ana);
        $id = $this->request('GET', '/me/notifications', token: $modToken)['items'][0]['id'];
        $this->request('DELETE', '/comunas/fechada/moderators/modera', token: $ownerToken);
        $this->request('GET', '/me/notifications/'.$id, token: $modToken);
        $this->assertStatus(404);
        self::assertSame(0, $this->request('GET', '/me/notifications/unread-count', token: $modToken)['unreadCount']);
        $this->request('DELETE', '/comunas/fechada/join-request', token: $ana);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $ownerToken)['items']);
        $this->request('PUT', '/comunas/fechada/join-request', token: $ana);
        $this->request('POST', '/comunas/fechada/archive', token: $ownerToken);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $ownerToken)['items']);
    }

    public function testOwnershipProposalAcceptanceAndConfirmationAreAtomicAndNotDuplicated(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $this->addMember($comuna, $this->createUser('ana'));
        $owner = $this->login('dona@exemplo.org')['accessToken'];
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $uri = '/comunas/tarifa/ownership-transfer';
        $this->request('PUT', $uri, ['handle' => 'ana'], $owner);
        $this->request('PUT', $uri, ['handle' => 'ana'], $owner);
        $items = $this->request('GET', '/me/notifications', token: $ana)['items'];
        self::assertCount(1, $items);
        self::assertSame('ownership_proposed', $items[0]['type']);
        $this->request('POST', $uri.'/accept', token: $ana);
        $this->assertStatus(200);
        foreach ([$owner, $ana] as $token) {
            $items = $this->request('GET', '/me/notifications', token: $token)['items'];
            self::assertCount(1, $items);
            self::assertSame('ownership_accepted', $items[0]['type']);
        }
        $this->request('POST', $uri.'/accept', token: $ana);
        $this->assertStatus(404);
        self::assertSame(3, $this->em()->getRepository(Notification::class)->count([]));
    }

    public function testOwnershipDeclineAndCancellationFollowExistingStates(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $this->addMember($comuna, $this->createUser('ana'));
        $owner = $this->login('dona@exemplo.org')['accessToken'];
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $uri = '/comunas/tarifa/ownership-transfer';
        $this->request('PUT', $uri, ['handle' => 'ana'], $owner);
        $this->request('POST', $uri.'/decline', token: $ana);
        self::assertSame('ownership_declined', $this->request('GET', '/me/notifications', token: $owner)['items'][0]['type']);
        $this->request('PUT', $uri, ['handle' => 'ana'], $owner);
        $this->request('DELETE', $uri, token: $owner);
        $this->request('DELETE', $uri, token: $owner);
        $items = $this->request('GET', '/me/notifications', token: $ana)['items'];
        self::assertCount(1, $items);
        self::assertSame('ownership_cancelled', $items[0]['type']);
    }

    public function testFailedOwnershipAcceptanceCreatesNoSuccessNotification(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $anaUser = $this->createUser('ana');
        foreach (range(1, 3) as $i) {
            $this->createComuna('ana-'.$i, owner: $anaUser);
        }
        $this->addMember($comuna, $anaUser);
        $owner = $this->login('dona@exemplo.org')['accessToken'];
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $this->request('PUT', '/comunas/tarifa/ownership-transfer', ['handle' => 'ana'], $owner);
        $this->request('POST', '/comunas/tarifa/ownership-transfer/accept', token: $ana);
        $this->assertStatus(409);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $owner)['items']);
        self::assertSame(['ownership_proposed'], array_column($this->request('GET', '/me/notifications', token: $ana)['items'], 'type'));
        self::assertSame('founder', $this->request('GET', '/comunas/tarifa', token: $owner)['viewer']['role']);
    }

    public function testBlocksHideHistoryAndPreventNewFollowNotifications(): void
    {
        $this->createUser('ana'); $this->createUser('bia');
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $bia = $this->login('bia@exemplo.org')['accessToken'];
        $this->request('PUT', '/users/bia/follow', token: $ana);
        $id = $this->request('GET', '/me/notifications', token: $bia)['items'][0]['id'];
        $this->request('PUT', '/me/blocks/ana', token: $bia);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $bia)['items']);
        self::assertSame(0, $this->request('GET', '/me/notifications/unread-count', token: $bia)['unreadCount']);
        $this->request('PUT', '/me/notifications/'.$id.'/read', token: $bia);
        $this->assertStatus(404);
        $this->request('PUT', '/users/bia/follow', token: $ana);
        $this->assertStatus(404);
        self::assertSame(1, $this->em()->getRepository(Notification::class)->count([]));
        $this->request('DELETE', '/me/blocks/ana', token: $bia);
        $this->request('PUT', '/me/blocks/bia', token: $ana);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $bia)['items']);
        self::assertSame(0, $this->request('GET', '/me/notifications/unread-count', token: $bia)['unreadCount']);
    }

    public function testDeletedPostIsNoLongerExposedByAnOldNotification(): void
    {
        $anaUser = $this->createUser('ana');
        $this->createUser('bia');
        $post = $this->createPost($anaUser, null);
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $bia = $this->login('bia@exemplo.org')['accessToken'];
        $uri = '/posts/'.$post->getId()->toRfc4122();
        $this->request('POST', $uri.'/comments', ['body' => 'Comentário'], $bia);
        $id = $this->request('GET', '/me/notifications', token: $ana)['items'][0]['id'];
        $this->request('DELETE', $uri, token: $ana);
        $this->assertStatus(204);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $ana)['items']);
        self::assertSame(0, $this->request('GET', '/me/notifications/unread-count', token: $ana)['unreadCount']);
        $this->request('GET', '/me/notifications/'.$id, token: $ana);
        $this->assertStatus(404);
    }

    public function testRemovedCommentAndLostPrivateMembershipRedactHistoryAndCount(): void
    {
        $ownerUser = $this->createUser('dona');
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $ownerUser);
        $author = $this->createUser('ana'); $visitor = $this->createUser('bia');
        $this->addMember($comuna, $author); $this->addMember($comuna, $visitor);
        $post = $this->createPost($author, $comuna);
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $bia = $this->login('bia@exemplo.org')['accessToken'];
        $uri = '/posts/'.$post->getId()->toRfc4122().'/comments';
        $comment = $this->request('POST', $uri, ['body' => 'Conteúdo privado'], $bia);
        self::assertSame(1, $this->request('GET', '/me/notifications/unread-count', token: $ana)['unreadCount']);
        $this->request('POST', '/comments/'.$comment['id'].'/removal', ['reason' => 'Conteúdo fora das regras da comuna.'], $this->login('dona@exemplo.org')['accessToken']);
        $this->assertStatus(204);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $ana)['items']);
        $this->request('POST', $uri, ['body' => 'Outro comentário privado'], $bia);
        $id = $this->request('GET', '/me/notifications', token: $ana)['items'][0]['id'];
        $this->request('DELETE', '/comunas/fechada/membership', token: $ana);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $ana)['items']);
        self::assertSame(0, $this->request('GET', '/me/notifications/unread-count', token: $ana)['unreadCount']);
        $this->request('GET', '/me/notifications/'.$id, token: $ana);
        $this->assertStatus(404);
    }

    public function testNotificationRollsBackWithItsBusinessAction(): void
    {
        $actor = $this->createUser('ana'); $recipient = $this->createUser('bia');
        $connection = $this->em()->getConnection();
        try {
            $connection->transactional(function () use ($actor, $recipient): void {
                $follow = new UserFollow($actor, $recipient, new \DateTimeImmutable());
                $this->em()->persist($follow);
                static::getContainer()->get(NotificationCreator::class)->followed($follow, $actor, $recipient);
                $this->em()->flush();
                throw new \RuntimeException('Rollback expected');
            });
        } catch (\RuntimeException $error) { self::assertSame('Rollback expected', $error->getMessage()); }
        $this->em()->clear();
        self::assertSame(0, $this->em()->getRepository(Notification::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(UserFollow::class)->count([]));
    }

    public function testDatabaseRejectsDuplicateEventForSameRecipient(): void
    {
        $recipient = $this->createUser('bia'); $actor = $this->createUser('ana');
        $this->em()->persist(new Notification($recipient, $actor, NotificationType::Follow, 'same-event', new \DateTimeImmutable()));
        $this->em()->persist(new Notification($recipient, $actor, NotificationType::Follow, 'same-event', new \DateTimeImmutable()));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }
}
