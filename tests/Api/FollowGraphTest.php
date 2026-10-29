<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Entity\UserFollow;
use App\Enum\AccountStatus;
use App\Enum\ComunaVisibility;
use App\Tests\ApiTestCase;

final class FollowGraphTest extends ApiTestCase
{
    public function testPublicFollowIsIdempotentAndUnfollowUpdatesCounts(): void
    {
        $this->createUser('ana');
        $this->createUser('bia');
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $bia = $this->login('bia@exemplo.org')['accessToken'];

        $first = $this->request('PUT', '/users/bia/follow', token: $ana);
        $this->assertStatus(200);
        self::assertTrue($first['following']);
        self::assertFalse($first['requested']);
        $second = $this->request('PUT', '/users/bia/follow', token: $ana);
        self::assertTrue($second['following']);
        self::assertSame(1, $this->em()->getRepository(UserFollow::class)->count([]));
        self::assertCount(1, $this->request('GET', '/me/notifications', token: $bia)['items']);
        self::assertSame(1, $this->request('GET', '/users/bia', token: $ana)['followerCount']);
        self::assertSame(0, $this->request('GET', '/users/bia', token: $ana)['followingCount']);
        self::assertSame(1, $this->request('GET', '/me', token: $ana)['followingCount']);
        self::assertTrue($this->request('GET', '/users/bia', token: $ana)['viewerFollowing']);

        $this->request('PUT', '/users/ana/follow', token: $ana);
        $this->assertStatus(400);
        $this->request('PUT', '/users/ninguem/follow', token: $ana);
        $this->assertStatus(404);

        $this->request('DELETE', '/users/bia/follow', token: $ana);
        $this->assertStatus(204);
        $this->request('DELETE', '/users/bia/follow', token: $ana);
        $this->assertStatus(204);
        $after = $this->request('GET', '/users/bia/follow', token: $ana);
        self::assertFalse($after['following']);
        self::assertFalse($after['requested']);
        self::assertSame(0, $this->request('GET', '/users/bia', token: $ana)['followerCount']);
        self::assertSame(0, $this->request('GET', '/me', token: $ana)['followingCount']);
    }

    public function testPrivateFollowStaysPendingUntilApprovedOrRejected(): void
    {
        $this->createUser('ana');
        $bia = $this->createUser('bia');
        $this->createPost($bia, null, 'mural privado');
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $biaToken = $this->login('bia@exemplo.org')['accessToken'];
        $this->request('PATCH', '/me', ['isPrivate' => true], $biaToken, 'application/merge-patch+json');

        $pending = $this->request('PUT', '/users/bia/follow', token: $ana);
        self::assertFalse($pending['following']);
        self::assertTrue($pending['requested']);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $biaToken)['items']);
        self::assertSame(0, $this->request('GET', '/users/bia', token: $ana)['followerCount']);
        self::assertSame([], $this->request('GET', '/feed?tab=seguindo', token: $ana)['items']);
        $this->request('GET', '/users/bia/followers', token: $ana);
        $this->assertStatus(403);
        self::assertSame('/errors/follow-list-private', $this->request('GET', '/users/bia/following', token: $ana)['type']);

        $this->request('DELETE', '/users/bia/follow', token: $ana);
        self::assertSame([], $this->request('GET', '/me/follow-requests', token: $biaToken)['items']);
        $again = $this->request('PUT', '/users/bia/follow', token: $ana);
        self::assertTrue($again['requested']);

        $requests = $this->request('GET', '/me/follow-requests', token: $biaToken);
        self::assertSame('ana', $requests['items'][0]['handle']);
        $this->request('DELETE', '/me/follow-requests/'.$requests['items'][0]['id'], token: $biaToken);
        $this->assertStatus(204);
        self::assertFalse($this->request('GET', '/users/bia/follow', token: $ana)['requested']);
        self::assertSame(0, $this->em()->getRepository(UserFollow::class)->count([]));

        $this->request('PUT', '/users/bia/follow', token: $ana);
        $id = $this->request('GET', '/me/follow-requests', token: $biaToken)['items'][0]['id'];
        $this->request('PUT', '/me/follow-requests/'.$id, token: $biaToken);
        $this->assertStatus(204);
        $this->request('PUT', '/me/follow-requests/'.$id, token: $biaToken);
        $this->assertStatus(204);
        self::assertTrue($this->request('GET', '/users/bia/follow', token: $ana)['following']);
        self::assertSame(1, $this->request('GET', '/users/bia', token: $biaToken)['followerCount']);
        $notes = $this->request('GET', '/me/notifications', token: $biaToken)['items'];
        self::assertCount(1, $notes);
        self::assertSame('follow', $notes[0]['type']);
        self::assertSame(['mural privado'], array_column($this->request('GET', '/feed?tab=seguindo', token: $ana)['items'], 'body'));
        $this->request('GET', '/users/bia/followers', token: $ana);
        $this->assertStatus(200);
    }

    public function testRelationListsArePagedAndCarryTheViewerRelation(): void
    {
        $this->createUser('ana');
        $this->createUser('bia');
        $this->createUser('carlos');
        $edu = $this->createUser('edu');
        $edu->setPrivate(true, new \DateTimeImmutable());
        $this->em()->flush();
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $carlos = $this->login('carlos@exemplo.org')['accessToken'];
        $eduToken = $this->login('edu@exemplo.org')['accessToken'];
        $this->request('PUT', '/users/bia/follow', token: $carlos);
        $this->request('PUT', '/users/bia/follow', token: $eduToken);
        $this->request('PUT', '/users/carlos/follow', token: $ana);
        $this->request('PUT', '/users/edu/follow', token: $ana);
        $this->request('PUT', '/users/carlos/follow', token: $eduToken);

        $page = $this->request('GET', '/users/bia/followers?limit=1', token: $ana);
        $this->assertStatus(200);
        self::assertCount(1, $page['items']);
        self::assertNotNull($page['nextCursor']);
        $rest = $this->request('GET', '/users/bia/followers?limit=1&cursor='.$page['nextCursor'], token: $ana);
        $handles = array_merge(array_column($page['items'], 'handle'), array_column($rest['items'], 'handle'));
        self::assertEqualsCanonicalizing(['carlos', 'edu'], $handles);
        self::assertNotSame($page['items'][0]['id'], $rest['items'][0]['id']);
        self::assertNull($rest['nextCursor']);
        $byHandle = [];
        foreach ([...$page['items'], ...$rest['items']] as $item) {
            $byHandle[$item['handle']] = $item;
            self::assertArrayHasKey('following', $item);
            self::assertArrayHasKey('requested', $item);
            self::assertArrayHasKey('isPrivate', $item);
        }
        self::assertTrue($byHandle['carlos']['following']);
        self::assertFalse($byHandle['carlos']['requested']);
        self::assertFalse($byHandle['edu']['following']);
        self::assertTrue($byHandle['edu']['requested']);
        self::assertTrue($byHandle['edu']['isPrivate']);

        $following = $this->request('GET', '/users/edu/following', token: $eduToken);
        self::assertEqualsCanonicalizing(['carlos', 'bia'], array_column($following['items'], 'handle'));
        $this->request('GET', '/users/edu/following', token: $ana);
        $this->assertStatus(403);
        $this->request('GET', '/users/bia/followers');
        $this->assertStatus(401);
    }

    public function testInactiveAndBlockedPeopleDoNotLeakThroughLists(): void
    {
        $nara = $this->createUser('nara');
        $this->createUser('bia');
        $this->createUser('ana');
        $naraToken = $this->login('nara@exemplo.org')['accessToken'];
        $bia = $this->login('bia@exemplo.org')['accessToken'];
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $this->request('PUT', '/users/bia/follow', token: $naraToken);
        $this->request('PUT', '/users/bia/follow', token: $ana);
        $suspended = $this->fresh(User::class, $nara->getId());
        $suspended->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();

        $followers = $this->request('GET', '/users/bia/followers', token: $bia);
        self::assertSame(['ana'], array_column($followers['items'], 'handle'));
        self::assertSame(1, $this->request('GET', '/users/bia', token: $bia)['followerCount']);

        $this->request('PUT', '/me/blocks/ana', token: $bia);
        $this->assertStatus(204);
        self::assertSame([], $this->request('GET', '/users/bia/followers', token: $bia)['items']);
        self::assertSame(1, $this->request('GET', '/users/bia', token: $bia)['followerCount']);
        $this->request('GET', '/users/bia/follow', token: $ana);
        $this->assertStatus(404);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM user_follow'));
        $this->request('PUT', '/users/bia/follow', token: $ana);
        $this->assertStatus(404);
    }

    public function testFollowingFeedUsesApprovedFollowsWithoutBypassingContentPolicy(): void
    {
        $bia = $this->createUser('bia');
        $carlos = $this->createUser('carlos');
        $this->createUser('ana');
        $open = $this->createComuna('aberta');
        $closed = $this->createComuna('fechada', ComunaVisibility::Private, $bia);
        $profile = $this->createPost($bia, null, 'mural da bia');
        $this->createPost($bia, $open, 'comuna aberta');
        $this->createPost($bia, $closed, 'comuna fechada');
        $this->createPost($carlos, null, 'mural do carlos');
        $ana = $this->login('ana@exemplo.org')['accessToken'];
        $biaToken = $this->login('bia@exemplo.org')['accessToken'];

        self::assertSame([], $this->request('GET', '/feed?tab=seguindo', token: $ana)['items']);
        $this->request('PUT', '/users/bia/follow', token: $ana);
        $bodies = array_column($this->request('GET', '/feed?tab=seguindo', token: $ana)['items'], 'body');
        self::assertEqualsCanonicalizing(['mural da bia', 'comuna aberta'], $bodies);
        self::assertNotContains('comuna fechada', $bodies);
        self::assertNotContains('mural do carlos', $bodies);

        $this->request('DELETE', '/posts/'.$profile->getId()->toRfc4122(), token: $biaToken);
        $this->assertStatus(204);
        self::assertSame(['comuna aberta'], array_column($this->request('GET', '/feed?tab=seguindo', token: $ana)['items'], 'body'));

        $this->request('DELETE', '/users/bia/follow', token: $ana);
        self::assertSame([], $this->request('GET', '/feed?tab=seguindo', token: $ana)['items']);

        $this->request('PATCH', '/me', ['isPrivate' => true], $biaToken, 'application/merge-patch+json');
        $this->createPost($this->fresh(User::class, $bia->getId()), null, 'so seguidores');
        $this->request('PUT', '/users/bia/follow', token: $ana);
        self::assertSame([], array_column($this->request('GET', '/feed?tab=seguindo', token: $ana)['items'], 'body'));
        $id = $this->request('GET', '/me/follow-requests', token: $biaToken)['items'][0]['id'];
        $this->request('PUT', '/me/follow-requests/'.$id, token: $biaToken);
        self::assertContains('so seguidores', array_column($this->request('GET', '/feed?tab=seguindo', token: $ana)['items'], 'body'));
        self::assertNotContains('comuna fechada', array_column($this->request('GET', '/feed?tab=seguindo', token: $ana)['items'], 'body'));
    }
}
