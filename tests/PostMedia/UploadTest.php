<?php

declare(strict_types=1);

namespace App\Tests\PostMedia;

use App\Entity\PostMedia;
use App\Tests\ApiTestCase;
use App\Tests\Library\MemoryStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class UploadTest extends ApiTestCase
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
        $path = tempnam(sys_get_temp_dir(), 'popularia-image-');
        self::assertIsString($path);
        $this->paths[] = $path;
        file_put_contents($path, '' === $content ? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true) : $content);

        return new UploadedFile($path, 'imagem.png', 'image/png', UPLOAD_ERR_OK, true);
    }

    /** @return array<string, mixed> */
    private function upload(string $token, string $clientId, UploadedFile $file): array
    {
        $this->client->request('POST', '/api/v1/post-media', parameters: ['metadata' => json_encode(['clientId' => $clientId, 'kind' => 'image'], JSON_THROW_ON_ERROR)], files: ['file' => $file], server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'multipart/form-data']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testImageUploadIsValidatedStoredAndIdempotent(): void
    {
        $user = $this->createUser('camila');
        $token = $this->login($user->getEmail())['accessToken'];
        $clientId = Uuid::v7()->toRfc4122();
        $first = $this->upload($token, $clientId, $this->file());
        $this->assertStatus(201);
        $second = $this->upload($token, $clientId, $this->file());
        $this->assertStatus(201);

        self::assertSame($first['id'], $second['id']);
        self::assertSame('image', $first['type']);
        self::assertSame('image/png', $first['mimeType']);
        self::assertSame(1, MemoryStorage::$puts);
        self::assertSame(1, $this->em()->getRepository(PostMedia::class)->count([]));
    }

    public function testFakeImageIsRejectedBeforeStorage(): void
    {
        $user = $this->createUser('camila');
        $problem = $this->upload($this->login($user->getEmail())['accessToken'], Uuid::v7()->toRfc4122(), $this->file('not an image'));
        $this->assertStatus(422);
        self::assertSame('/errors/post-media-format', $problem['type']);
        self::assertSame(0, MemoryStorage::$puts);
    }
}
