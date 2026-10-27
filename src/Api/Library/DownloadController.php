<?php

declare(strict_types=1);

namespace App\Api\Library;

use App\Library\LibraryAccess;
use App\Library\ObjectStorage;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final readonly class DownloadController
{
    /** TTL da URL assinada em segundos. Curto o suficiente para tornar revogação prática. */
    private const int SIGNED_URL_TTL = 300;

    public function __construct(private CurrentUser $user, private LibraryAccess $access, private ObjectStorage $storage)
    {
    }

    public function __invoke(string $id): RedirectResponse
    {
        $document = $this->access->find($id);
        $this->access->assertRead($document, $this->user->get());
        $url = $this->storage->signedUrl($document->file->storageKey, self::SIGNED_URL_TTL);

        return new RedirectResponse($url, 302, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
