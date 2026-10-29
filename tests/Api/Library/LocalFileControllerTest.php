<?php

declare(strict_types=1);

namespace App\Tests\Api\Library;

use App\Api\Library\LocalFileController;
use App\Library\LocalFilesystemStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LocalFileControllerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/popularia-local-file-controller-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/post-media/video.mp4');
        @rmdir($this->directory.'/post-media');
        @rmdir($this->directory);
    }

    public function testStreamsARequestedByteRangeForVideo(): void
    {
        $secret = 'test-secret';
        $storage = new LocalFilesystemStorage($this->directory, $secret);
        mkdir($this->directory.'/post-media', 0700, true);
        file_put_contents($this->directory.'/post-media/video.mp4', '0123456789');
        $expires = time() + 60;
        $key = 'post-media/video.mp4';
        $signature = hash_hmac('sha256', $key."\n".$expires, $secret);
        $request = Request::create('/api/dev/library-files', 'GET', ['key' => $key, 'expires' => $expires, 'signature' => $signature, 'type' => 'video/mp4', 'disposition' => 'inline'], server: ['HTTP_RANGE' => 'bytes=2-5']);

        $response = (new LocalFileController($storage, $secret))($request);
        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes', $response->headers->get('Accept-Ranges'));
        self::assertSame('bytes 2-5/10', $response->headers->get('Content-Range'));
        self::assertSame('4', $response->headers->get('Content-Length'));
        ob_start();
        $response->sendContent();
        self::assertSame('2345', ob_get_clean());
    }
}
