<?php

declare(strict_types=1);

namespace App\Library;

use App\Api\ApiProblem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class LocalFilesystemStorage implements ObjectStorage
{
    public function __construct(
        private readonly string $root = __DIR__.'/../../var/library',
        #[Autowire('%kernel.secret%')] private readonly string $secret = '',
    ) {
    }

    public function put(string $key, string $path, string $checksum, string $contentType = 'application/pdf'): void
    {
        $actual = hash_file('sha256', $path);
        if (false === $actual) {
            throw ApiProblem::serviceUnavailable();
        }
        if (!hash_equals($checksum, $actual)) {
            throw new \RuntimeException('Checksum incorreto.');
        }

        $destination = $this->resolve($key);
        $this->ensureDirectory(dirname($destination));
        $source = fopen($path, 'rb');
        $target = fopen($destination, 'wb');
        if (false === $source || false === $target) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            throw ApiProblem::serviceUnavailable();
        }
        try {
            if (false === stream_copy_to_stream($source, $target)) {
                throw ApiProblem::serviceUnavailable();
            }
        } finally {
            fclose($source);
            fclose($target);
        }
        if (!chmod($destination, 0600)) {
            throw ApiProblem::serviceUnavailable();
        }
    }

    public function read(string $key): mixed
    {
        $path = $this->resolve($key);
        if (!is_file($path) || !is_readable($path)) {
            throw ApiProblem::notFound();
        }

        $stream = fopen($path, 'rb');
        if (false === $stream) {
            throw ApiProblem::serviceUnavailable();
        }

        return $stream;
    }

    public function size(string $key): int
    {
        $path = $this->resolve($key);
        $size = is_file($path) ? filesize($path) : false;
        if (false === $size || $size < 1) {
            throw ApiProblem::notFound();
        }

        return $size;
    }

    /** @return resource */
    public function readFrom(string $key, int $offset): mixed
    {
        $stream = $this->read($key);
        if ($offset < 0 || 0 !== fseek($stream, $offset, SEEK_SET)) {
            fclose($stream);
            throw ApiProblem::notFound();
        }

        return $stream;
    }

    public function signedUrl(string $key, int $ttl, string $contentType = 'application/pdf', string $disposition = 'attachment; filename="documento.pdf"'): string
    {
        if ($ttl < 1 || $ttl > 86400) {
            throw ApiProblem::serviceUnavailable();
        }

        $expires = time() + $ttl;
        $signature = hash_hmac('sha256', $key."\n".$expires, $this->secret);

        return '/api/dev/library-files?'.http_build_query(['key' => $key, 'expires' => $expires, 'signature' => $signature, 'type' => $contentType, 'disposition' => $disposition]);
    }

    public function delete(string $key): void
    {
        $path = $this->resolve($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function resolve(string $key): string
    {
        if ('' === $key || !preg_match('/^[a-zA-Z0-9._\/-]+$/', $key)
            || in_array('..', explode('/', $key), true) || in_array('.', explode('/', $key), true)) {
            throw ApiProblem::serviceUnavailable();
        }

        return $this->root.'/'.ltrim($key, '/');
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw ApiProblem::serviceUnavailable();
        }

        if (!chmod($dir, 0700)) {
            throw ApiProblem::serviceUnavailable();
        }
    }
}
