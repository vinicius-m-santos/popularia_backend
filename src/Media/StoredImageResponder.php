<?php

declare(strict_types=1);

namespace App\Media;

use App\Api\ApiProblem;
use App\Library\ObjectStorage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Imagens de apresentação pública, com revalidação curta após suspensão ou troca. */
final readonly class StoredImageResponder
{
    public function __construct(private ObjectStorage $storage)
    {
    }

    public function stream(?string $key, string $prefix, string $fileId): Response
    {
        if (!StoredImageUrl::matches($prefix, $key, $fileId)) {
            throw ApiProblem::notFound();
        }
        \assert(is_string($key));
        $extension = str_ends_with($key, '.png') ? 'png' : (str_ends_with($key, '.webp') ? 'webp' : 'jpg');
        $type = ['png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg'][$extension];
        $stream = $this->storage->read($key);

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=300, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
