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

    public function put(string $key, string $path, string $checksum): void
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw ApiProblem::serviceUnavailable();
        }

        if (!hash_equals($checksum, hash('sha256', $content))) {
            throw new \RuntimeException('Checksum incorreto.');
        }

        $destination = $this->resolve($key);
        $this->ensureDirectory(dirname($destination));
        $written = file_put_contents($destination, $content);
        if (false === $written || !chmod($destination, 0600)) {
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

    public function signedUrl(string $key, int $ttl): string
    {
        if ($ttl < 1 || $ttl > 86400) {
            throw ApiProblem::serviceUnavailable();
        }

        $expires = time() + $ttl;
        $signature = hash_hmac('sha256', $key."\n".$expires, $this->secret);

        return '/api/dev/library-files?'.http_build_query(['key' => $key, 'expires' => $expires, 'signature' => $signature]);
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