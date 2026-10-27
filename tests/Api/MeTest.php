<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AuthSession;
use App\Entity\User;
use App\Tests\ApiTestCase;

final class MeTest extends ApiTestCase
{
    public function testPrivateEndpointsRequireAuthentication(): void
    {
        foreach ([['GET', '/me'], ['PATCH', '/me'], ['GET', '/me/sessions'], ['POST', '/me/password'], ['POST', '/auth/logout-all']] as [$method, $uri]) {
            $body = $this->request($method, $uri, 'GET' === $method ? null : [], contentType: 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json');
            $this->assertStatus(401);
            self::assertSame('/errors/unauthorized', $body['type'], $method.' '.$uri);
        }
    }

    public function testMeReturnsOwnAccount(): void
    {
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $me = $this->request('GET', '/me', token: $token);

        $this->assertStatus(200);
        self::assertSame(
            ['id', 'handle', 'displayName', 'email', 'emailVerified', 'bio', 'city', 'interests', 'platformRole', 'createdAt', 'updatedAt', 'isPrivate', 'pendingOnboarding', 'hasPassword'],
            array_keys($me),
        );
        self::assertSame('camila@exemplo.org', $me['email']);
        self::assertArrayNotHasKey('password', $me);
    }

    public function testPatchUpdatesAllowedFields(): void
    {
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $me = $this->request('PATCH', '/me', ['displayName' => 'Camila R.', 'bio' => "Linha 1\nLinha 2", 'city' => 'Recife · PE', 'interests' => ['clima', 'cult']], $token, 'application/merge-patch+json');

        $this->assertStatus(200);
        self::assertSame('Camila R.', $me['displayName']);
        self::assertSame("Linha 1\nLinha 2", $me['bio']);
        self::assertSame('Recife · PE', $me['city']);
        self::assertSame(['clima', 'cult'], $me['interests']);

        $me = $this->request('PATCH', '/me', ['city' => ''], $token, 'application/merge-patch+json');
        self::assertNull($me['city']);
        self::assertSame('Camila R.', $me['displayName']);
    }

    public function testPatchCannotChangeProtectedFields(): void
    {
        $user = $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        foreach ([['email' => 'outro@exemplo.org'], ['handle' => 'outro'], ['platformRole' => 'admin'], ['id' => '00000000-0000-0000-0000-000000000001'], ['emailVerified' => true], ['status' => 'active']] as $patch) {
            $this->request('PATCH', '/me', $patch, $token, 'application/merge-patch+json');
            $this->assertStatus(400);
        }

        $fresh = $this->fresh(User::class, $user->getId());
        self::assertSame('camila@exemplo.org', $fresh->getEmail());
        self::assertSame('camila', $fresh->getHandle());
        self::assertSame('user', $fresh->getPlatformRole()->value);
    }

    public function testPatchValidatesInput(): void
    {
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $body = $this->request('PATCH', '/me', ['displayName' => '', 'bio' => str_repeat('a', 161), 'interests' => ['xyz']], $token, 'application/merge-patch+json');

        $this->assertStatus(422);
        self::assertEqualsCanonicalizing(['displayName', 'bio', 'interests[0]'], array_column((array) $body['violations'], 'propertyPath'));
    }

    public function testSessionsListShowsOnlyOwnSessionsWithoutSecrets(): void
    {
        $this->createUser('camila');
        $this->createUser('outra');
        $mine = $this->login('camila@exemplo.org');
        $this->login('outra@exemplo.org');

        $sessions = $this->request('GET', '/me/sessions', token: $mine['accessToken']);

        $this->assertStatus(200);
        self::assertCount(1, $sessions);
        self::assertSame(['id', 'deviceLabel', 'createdAt', 'lastUsedAt', 'current'], array_keys($sessions[0]));
        self::assertTrue($sessions[0]['current']);
    }

    public function testCannotRevokeAnotherAccountsSessionById(): void
    {
        $this->createUser('camila');
        $this->createUser('outra');
        $mine = $this->login('camila@exemplo.org');
        $theirs = $this->login('outra@exemplo.org');

        $this->request('DELETE', '/me/sessions/'.$theirs['sessionId'], token: $mine['accessToken']);

        $this->assertStatus(404);
        self::assertFalse($this->fresh(AuthSession::class, $theirs['sessionId'])->isRevoked());
        $this->request('GET', '/me', token: $theirs['accessToken']);
        $this->assertStatus(200);
    }

    public function testRevokeOwnOtherSession(): void
    {
        $this->createUser('camila');
        $phone = $this->login('camila@exemplo.org');
        $tablet = $this->login('camila@exemplo.org');

        $this->request('DELETE', '/me/sessions/'.$tablet['sessionId'], token: $phone['accessToken']);

        $this->assertStatus(204);
        $this->request('GET', '/me', token: $tablet['accessToken']);
        $this->assertStatus(401);
    }

    public function testPasswordChangeKeepsCurrentSessionAndEndsOthers(): void
    {
        $this->createUser('camila');
        $phone = $this->login('camila@exemplo.org');
        $tablet = $this->login('camila@exemplo.org');

        $this->request('POST', '/me/password', ['currentPassword' => 'errada', 'newPassword' => 'nova senha boa'], $phone['accessToken']);
        $this->assertStatus(403);

        $this->request('POST', '/me/password', ['currentPassword' => self::PASSWORD, 'newPassword' => 'nova senha boa'], $phone['accessToken']);
        $this->assertStatus(204);

        $this->request('GET', '/me', token: $phone['accessToken']);
        $this->assertStatus(200);
        $this->request('GET', '/me', token: $tablet['accessToken']);
        $this->assertStatus(401);
        $this->login('camila@exemplo.org', 'nova senha boa');
    }
}
