<?php

declare(strict_types=1);

namespace App\Tests\Library;

use App\Library\LocalFilesystemStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalFilesystemStorage::class)]
final class LocalFilesystemStorageTest extends TestCase
{
    public function testWritesReadsAndBuildsLocalSignedUrl(): void
    {
        $dir = sys_get_temp_dir().'/popularia-local-storage-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $storage = new LocalFilesystemStorage($dir);
        $path = $dir.'/tmp-sample.pdf';
        $content = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
        file_put_contents($path, $content);

        $storage->put('library/seed.pdf', $path, hash('sha256', $content));

        self::assertFileExists($dir.'/library/seed.pdf');
        $stream = $storage->read('library/seed.pdf');
        self::assertIsResource($stream);
        self::assertSame($content, stream_get_contents($stream));
        fclose($stream);
        $signedUrl = $storage->signedUrl('library/seed.pdf', 30);
        self::assertStringStartsWith('/api/dev/library-files?', $signedUrl);
        self::assertStringContainsString('key=library%2Fseed.pdf', $signedUrl);
        self::assertStringContainsString('signature=', $signedUrl);

        $storage->delete('library/seed.pdf');
        self::assertFileDoesNotExist($dir.'/library/seed.pdf');

        unlink($path);
        @rmdir($dir.'/library');
        @rmdir($dir);
    }
}
