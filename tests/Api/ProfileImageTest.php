<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Comuna;
use App\Entity\User;
use App\Enum\ComunaRole;
use App\Tests\ApiTestCase;
use App\Tests\Library\MemoryStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProfileImageTest extends ApiTestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        MemoryStorage::$objects = [];
        MemoryStorage::$puts = 0;
        MemoryStorage::$fail = false;
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function file(string $content = ''): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'popularia-profile-');
        self::assertIsString($path);
        $this->paths[] = $path;
        file_put_contents($path, '' === $content ? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true) : $content);

        return new UploadedFile($path, 'imagem.png', 'image/png', UPLOAD_ERR_OK, true);
    }

    /** @return array<string, mixed> */
    private function upload(string $uri, ?string $token, UploadedFile $file): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'multipart/form-data'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request('POST', '/api/v1'.$uri, files: ['file' => $file], server: $server);

        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : (array) json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }

    public function testOwnerReplacesAvatarAndOldUrlStopsWorking(): void
    {
        $user = $this->createUser('camila');
        $token = $this->login($user->getEmail())['accessToken'];

        $first = $this->upload('/me/avatar', $token, $this->file());
        $this->assertStatus(200);
        self::assertSame($user->getId()->toRfc4122(), $first['id']);
        self::assertIsString($first['avatarUrl']);
        self::assertStringStartsWith('/api/v1/media/avatars/'.$user->getId()->toRfc4122().'/', $first['avatarUrl']);

        $this->client->request('GET', $first['avatarUrl']);
        $this->assertStatus(200);
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertNotSame('', $this->client->getResponse()->getContent());

        $profile = $this->request('GET', '/users/camila');
        self::assertSame($first['avatarUrl'], $profile['avatarUrl']);

        $second = $this->upload('/me/avatar', $token, $this->file());
        $this->assertStatus(200);
        self::assertNotSame($first['avatarUrl'], $second['avatarUrl']);
        self::assertSame(2, MemoryStorage::$puts);
        self::assertCount(1, MemoryStorage::$objects);

        $this->client->request('GET', $first['avatarUrl']);
        $this->assertStatus(404);
        $this->client->request('GET', $second['avatarUrl']);
        $this->assertStatus(200);
        self::assertNotNull($this->fresh(User::class, $user->getId())->getAvatarKey());
    }

    public function testAvatarRequiresAuthenticationAndRejectsInvalidFiles(): void
    {
        $this->upload('/me/avatar', null, $this->file());
        $this->assertStatus(401);

        $user = $this->createUser('camila');
        $problem = $this->upload('/me/avatar', $this->login($user->getEmail())['accessToken'], $this->file('not an image'));
        $this->assertStatus(422);
        self::assertSame('/errors/post-media-format', $problem['type']);
        self::assertSame(0, MemoryStorage::$puts);
        self::assertNull($this->fresh(User::class, $user->getId())->getAvatarKey());
    }

    public function testOnlyTheOwnerReplacesTheComunaCover(): void
    {
        $owner = $this->createUser('dona');
        $moderator = $this->createUser('mod');
        $member = $this->createUser('membro');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $this->addMember($comuna, $moderator, ComunaRole::Moderator);
        $this->addMember($comuna, $member);

        $denied = $this->upload('/comunas/tarifa/cover', $this->login($member->getEmail())['accessToken'], $this->file());
        $this->assertStatus(403);
        self::assertSame('/errors/not-comuna-owner', $denied['type']);
        $this->upload('/comunas/tarifa/cover', $this->login($moderator->getEmail())['accessToken'], $this->file());
        $this->assertStatus(403);
        self::assertSame(0, MemoryStorage::$puts);

        $saved = $this->upload('/comunas/tarifa/cover', $this->login($owner->getEmail())['accessToken'], $this->file());
        $this->assertStatus(200);
        self::assertIsString($saved['coverUrl']);
        self::assertStringStartsWith('/api/v1/media/comuna-covers/'.$comuna->getId()->toRfc4122().'/', $saved['coverUrl']);
        $replaced = $this->upload('/comunas/tarifa/cover', $this->login($owner->getEmail())['accessToken'], $this->file());
        self::assertNotSame($saved['coverUrl'], $replaced['coverUrl']);
        $this->client->request('GET', $replaced['coverUrl']);
        $this->assertStatus(200);
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));

        $stored = $this->fresh(Comuna::class, $comuna->getId());
        $stored->archive(new \DateTimeImmutable());
        $this->em()->flush();
        $archived = $this->upload('/comunas/tarifa/cover', $this->login($owner->getEmail())['accessToken'], $this->file());
        $this->assertStatus(409);
        self::assertSame('/errors/comuna-archived', $archived['type']);
        self::assertNotNull($this->fresh(Comuna::class, $comuna->getId())->getCoverKey());
    }
}
