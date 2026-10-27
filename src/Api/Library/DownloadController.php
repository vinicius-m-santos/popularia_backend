<?php

declare(strict_types=1);

namespace App\Api\Library;

use App\Library\LibraryAccess;
use App\Library\ObjectStorage;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final readonly class DownloadController
{
    public function __construct(private CurrentUser $user, private LibraryAccess $access, private ObjectStorage $storage)
    {
    }

    public function __invoke(string $id): StreamedResponse
    {
        $document = $this->access->find($id);
        $this->access->assertRead($document, $this->user->get());
        $stream = $this->storage->read($document->file->storageKey);

        return new StreamedResponse(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="documento.pdf"',
            'Content-Length' => (string) $document->file->byteSize,
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }
}
