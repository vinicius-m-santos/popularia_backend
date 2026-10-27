<?php

declare(strict_types=1);

namespace App\Library;

interface ObjectStorage
{
    public function put(string $key, string $path, string $checksum): void;

    /** @return resource */
    public function read(string $key): mixed;

    public function delete(string $key): void;
}
