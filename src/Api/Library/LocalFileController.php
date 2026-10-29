<?php

declare(strict_types=1);

namespace App\Api\Library;

use App\Api\ApiProblem;
use App\Library\LocalFilesystemStorage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LocalFileController
{
    public function __construct(private readonly LocalFilesystemStorage $storage, private readonly string $appSecret)
    {
    }

    public function __invoke(Request $request): Response
    {
        $key = $request->query->getString('key');
        $expires = $request->query->getInt('expires');
        $signature = $request->query->getString('signature');
        $expected = hash_hmac('sha256', $key."\n".$expires, $this->appSecret);
        $type = $request->query->getString('type', 'application/pdf');
        $disposition = $request->query->getString('disposition', 'attachment; filename="documento.pdf"');

        if ($expires < time() || $expires > time() + 86400 || '' === $signature || !hash_equals($expected, $signature)) {
            throw ApiProblem::notFound();
        }

        $size = $this->storage->size($key);
        $range = $this->range($request->headers->get('Range'), $size);
        if (false === $range) {
            return new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, ['Content-Range' => "bytes */$size"]);
        }

        [$offset, $length] = $range ?? [0, $size];
        $stream = $this->storage->readFrom($key, $offset);
        $headers = [
            'Content-Type' => preg_match('#^(application/pdf|image/(jpeg|png|webp)|video/(mp4|quicktime))$#', $type) ? $type : 'application/octet-stream',
            'Content-Disposition' => str_starts_with($disposition, 'inline') ? 'inline' : 'attachment; filename="documento.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) $length,
        ];
        $status = Response::HTTP_OK;
        if (null !== $range) {
            $status = Response::HTTP_PARTIAL_CONTENT;
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $size);
        }

        return new StreamedResponse(static function () use ($stream, $length): void {
            $remaining = $length;
            while ($remaining > 0 && !feof($stream)) {
                $chunk = fread($stream, min(8192, $remaining));
                if (false === $chunk) {
                    break;
                }
                $remaining -= strlen($chunk);
                echo $chunk;
            }
            fclose($stream);
        }, $status, $headers);
    }

    /** @return array{int, int}|null|false */
    private function range(?string $header, int $size): array|null|false
    {
        if (null === $header || '' === $header) {
            return null;
        }
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $header, $parts)) {
            return false;
        }
        [, $start, $end] = $parts;
        if ('' === $start && '' === $end) {
            return false;
        }
        if ('' === $start) {
            $length = min((int) $end, $size);

            return $length > 0 ? [$size - $length, $length] : false;
        }
        $offset = (int) $start;
        if ($offset >= $size) {
            return false;
        }
        $last = '' === $end ? $size - 1 : min((int) $end, $size - 1);
        if ($last < $offset) {
            return false;
        }

        return [$offset, $last - $offset + 1];
    }
}
