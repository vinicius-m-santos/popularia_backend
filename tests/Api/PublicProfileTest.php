<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Enum\AccountStatus;
use App\Tests\ApiTestCase;

final class PublicProfileTest extends ApiTestCase
{
    public function testPublicProfileHasNoPrivateData(): void
    {
        $user = $this->createUser('camila');
        $user->updateProfile(null, 'Bio pública', 'São Paulo · SP', ['lgbt', 'pol'], new \DateTimeImmutable());
        $this->em()->flush();

        $profile = $this->request('GET', '/users/camila');

        $this->assertStatus(200);
        self::assertSame(['id', 'handle', 'displayName', 'bio', 'city', 'memberSince', 'isPrivate', 'followerCount', 'followingCount', 'viewerFollowing', 'viewerRequested', 'avatarUrl'], array_keys($profile));
        $json = (string) $this->client->getResponse()->getContent();
        foreach (['camila@exemplo.org', 'lgbt', 'interests', 'email', 'password', 'platformRole', 'status'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testSuspendedOrUnknownProfileIs404(): void
    {
        $user = $this->createUser('camila');
        $user->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();

        $this->request('GET', '/users/camila');
        $this->assertStatus(404);
        $this->request('GET', '/users/ninguem');
        $this->assertStatus(404);
    }

    public function testHandleAvailability(): void
    {
        $this->createUser('camila');

        self::assertFalse($this->request('GET', '/handles/camila/availability')['available']);
        self::assertFalse($this->request('GET', '/handles/admin/availability')['available']);
        self::assertFalse($this->request('GET', '/handles/a/availability')['available']);
        self::assertTrue($this->request('GET', '/handles/camila.rocha/availability')['available']);
    }

    public function testTopicsMatchTheApp(): void
    {
        $topics = $this->request('GET', '/topics');

        $this->assertStatus(200);
        self::assertCount(18, $topics);
        self::assertSame(['id' => 'pol', 'label' => 'Política brasileira', 'shortLabel' => 'Política', 'group' => 'Política e direitos'], $topics[0]);
    }
}
