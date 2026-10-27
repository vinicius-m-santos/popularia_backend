<?php

declare(strict_types=1);

namespace App\Library;

interface ObjectStorage
{
    public function put(string $key, string $path, string $checksum): void;

    /** @return resource */
    public function read(string $key): mixed;

    /** Gera URL assinada de leitura de curta duração. O backend é a única autoridade de autorização: nunca gera URL antes de verificar acesso. */
    public function signedUrl(string $key, int $ttl): string;

    public function delete(string $key): void;
}
