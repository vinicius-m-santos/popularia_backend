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
        // A consulta separada evita apresentar um PDF protegido como se estivesse corrompido.
        //
        // O qpdf do runner do GitHub Actions tem confinamento de caminho e nega
        // arquivos em /tmp. Copiar para var/tmp mantém o binário trabalhando
        // dentro do projeto, no CI e no container.
        $safePath = $this->copyToSafePath($path);
        try {
            $password = $this->qpdf($safePath, '--requires-password');
            if (0 === $password['exit']) {
                throw new ApiProblem(422, '/errors/pdf-password-protected', 'Este PDF está protegido por senha. Remova a senha e tente novamente.');
            }
            $check = $this->qpdf($safePath, '--check');
            // qpdf usa 3 para avisos recuperáveis. Esses PDFs continuam íntegros e podem ser lidos.
            if (!in_array($check['exit'], [0, 3], true)) {
                throw new ApiProblem(422, '/errors/pdf-corrupt', 'Este PDF está corrompido. Exporte uma nova cópia e tente novamente.');
            }
            $pageResult = $this->qpdf($safePath, '--show-npages');
            $pages = (int) trim($pageResult['stdout']);
            if (!in_array($pageResult['exit'], [0, 3], true) || $pages < 1) {
                throw new ApiProblem(422, '/errors/pdf-unreadable', 'O PDF parece válido, mas não conseguimos interpretar suas páginas. Exporte-o novamente em um formato PDF padrão.');
            }

            return ['checksum' => hash_file('sha256', $safePath) ?: throw ApiProblem::serviceUnavailable(), 'byteSize' => $size, 'pageCount' => $pages];
        } finally {
            @unlink($safePath);
        }
    }

    private function copyToSafePath(string $path): string
    {
        $directory = \dirname(__DIR__, 2).'/var/tmp';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw ApiProblem::serviceUnavailable();
        }
        $copy = tempnam($directory, 'pdf-');
        if (false === $copy) {
            throw ApiProblem::serviceUnavailable();
        }
        if (!copy($path, $copy)) {
            @unlink($copy);
            throw ApiProblem::serviceUnavailable();
        }

        return $copy;
    }

    /** @return array{exit: int, stdout: string} */
    private function qpdf(string $path, string $operation): array
    {
        $process = @proc_open(['timeout', '--signal=KILL', '20s', 'prlimit', '--as=268435456', '--cpu=20', '--', 'qpdf', $operation, $path], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw ApiProblem::serviceUnavailable();
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if (in_array($exit, [124, 127], true)) {
            throw ApiProblem::serviceUnavailable();
        }
        if (!in_array($exit, [0, 3], true) && 'test' === ($_SERVER['APP_ENV'] ?? '')) {
            error_log(sprintf('qpdf %s exit=%d path=%s stderr=%s', $operation, $exit, $path, trim($stderr)));
        }

        return ['exit' => $exit, 'stdout' => $stdout];
    }
}
