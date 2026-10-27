<?php

declare(strict_types=1);

namespace App\Tests\Library;

use App\Api\ApiProblem;
use App\Library\ObjectStorage;

final class MemoryStorage implements ObjectStorage
{
    /** @var array<string, string> */
    public static array $objects = [];
    public static int $puts = 0;
    public static int $reads = 0;
    public static bool $fail = false;
    public static bool $failDeletes = false;

    public function put(string $key, string $path, string $checksum): void
    {
        ++self::$puts;
        if (self::$fail) {
            throw ApiProblem::serviceUnavailable();
        }
        $content = file_get_contents($path);
        \assert(is_string($content));
        if (!hash_equals($checksum, hash('sha256', $content))) {
            throw new \RuntimeException('Checksum incorreto.');
        }
        self::$objects[$key] = $content;
    }

    public function read(string $key): mixed
    {
        ++self::$reads;
        $stream = fopen('php://temp', 'w+');
        \assert(is_resource($stream));
        fwrite($stream, self::$objects[$key] ?? throw ApiProblem::notFound());
        rewind($stream);

        return $stream;
    }

    public function signedUrl(string $key, int $ttl): string
    {
        $expires = (string) (time() + $ttl);

        return 'https://storage.test/'.rawurlencode($key).'?expires='.$expires;
    }

    public function delete(string $key): void
    {
        if (self::$failDeletes) {
            throw ApiProblem::serviceUnavailable();
        }
        unset(self::$objects[$key]);
    }
}
