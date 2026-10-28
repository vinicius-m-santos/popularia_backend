<?php

declare(strict_types=1);

namespace App\Api\Library;

use App\Api\ApiProblem;
use App\Library\LocalFilesystemStorage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LocalFileController
{
    public function __construct(private readonly LocalFilesystemStorage $storage, private readonly string $appSecret)
    {
    }

    public function __invoke(Request $request): StreamedResponse
    {
        $key = $request->query->getString('key');
        $expires = $request->query->getInt('expires');
        $signature = $request->query->getString('signature');
        $expected = hash_hmac('sha256', $key."\n".$expires, $this->appSecret);

        if ($expires < time() || $expires > time() + 86400 || '' === $signature || !hash_equals($expected, $signature)) {
            throw ApiProblem::notFound();
        }

        $stream = $this->storage->read($key);

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="documento.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}