<?php

declare(strict_types=1);

namespace App\Library;

use App\Api\ApiProblem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PdfValidator
{
    public const int MAX_BYTES = 25 * 1024 * 1024;

    /** @return array{checksum: string, byteSize: int, pageCount: int} */
    public function validate(?UploadedFile $file): array
    {
        if (null === $file || !$file->isValid()) {
            throw new ApiProblem(422, '/errors/pdf-missing', 'Selecione um PDF válido. Se o envio foi interrompido, tente novamente.');
        }
        $size = $file->getSize();
        if (false === $size || $size < 1 || $size > self::MAX_BYTES) {
            throw new ApiProblem(422, '/errors/pdf-size', 'O PDF precisa ter conteúdo e no máximo 25 MB.');
        }
        $path = $file->getPathname();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $stream = fopen($path, 'rb');
        $magic = false === $stream ? '' : fread($stream, 8);
        if (false !== $stream) {
            fclose($stream);
        }
        if ('pdf' !== strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION))
            || 'application/pdf' !== $mime || !is_string($magic) || !preg_match('/^%PDF-\d\.\d/', $magic)
            || !in_array($file->getClientMimeType(), ['application/pdf', 'application/octet-stream'], true)) {
            throw new ApiProblem(422, '/errors/pdf-format', 'Esse arquivo não é um PDF. Exporte como PDF e tente de novo.');
        }
        // Um parser real valida a estrutura; magic bytes sozinhos não detectam corrupção.
        $process = @proc_open(['timeout', '--signal=KILL', '20s', 'prlimit', '--as=268435456', '--cpu=20', '--', 'qpdf', '--check', $path], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw ApiProblem::serviceUnavailable();
        }
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        if (127 === $exit) {
            throw ApiProblem::serviceUnavailable();
        }
        if (0 !== $exit) {
            throw new ApiProblem(422, '/errors/pdf-corrupt', 'Este PDF está corrompido ou protegido por senha. Envie uma versão que possa ser aberta.');
        }
        $process = proc_open(['timeout', '--signal=KILL', '20s', 'prlimit', '--as=268435456', '--cpu=20', '--', 'qpdf', '--show-npages', $path], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw ApiProblem::serviceUnavailable();
        }
        $pages = (int) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (0 !== proc_close($process) || $pages < 1) {
            throw new ApiProblem(422, '/errors/pdf-corrupt', 'Não foi possível ler este PDF.');
        }

        return ['checksum' => hash_file('sha256', $path) ?: throw ApiProblem::serviceUnavailable(), 'byteSize' => $size, 'pageCount' => $pages];
    }
}
