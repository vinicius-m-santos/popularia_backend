<?php

declare(strict_types=1);

namespace App\PostMedia;

use App\Api\ApiProblem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PostMediaValidator
{
    public const IMAGE_MAX_BYTES = 10 * 1024 * 1024;
    public const VIDEO_MAX_BYTES = 100 * 1024 * 1024;
    public const VIDEO_MAX_DURATION_MS = 30000;
    private const IMAGE_MAX_PIXELS = 40_000_000;

    /** @return array{kind: string, mimeType: string, extension: string, checksum: string, byteSize: int, width: int, height: int, durationMs: int|null} */
    public function validate(?UploadedFile $file, string $expectedKind): array
    {
        if (null === $file || !$file->isValid() || !in_array($expectedKind, ['image', 'video'], true)) {
            throw new ApiProblem(422, '/errors/post-media-missing', 'Selecione uma imagem ou um vídeo válido.');
        }
        $size = $file->getSize();
        $limit = 'image' === $expectedKind ? self::IMAGE_MAX_BYTES : self::VIDEO_MAX_BYTES;
        if (false === $size || $size < 1 || $size > $limit) {
            $label = 'image' === $expectedKind ? '10 MB' : '100 MB';
            throw new ApiProblem(422, '/errors/post-media-size', "O arquivo precisa ter conteúdo e no máximo $label.");
        }
        $path = $file->getPathname();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime)) {
            throw new ApiProblem(422, '/errors/post-media-format', 'Não foi possível identificar o formato do arquivo.');
        }

        $metadata = 'image' === $expectedKind ? $this->image($path, $mime) : $this->video($path, $mime);

        return [
            'kind' => $expectedKind,
            'mimeType' => $mime,
            'extension' => $metadata['extension'],
            'checksum' => hash_file('sha256', $path) ?: throw ApiProblem::serviceUnavailable(),
            'byteSize' => $size,
            'width' => $metadata['width'],
            'height' => $metadata['height'],
            'durationMs' => $metadata['durationMs'],
        ];
    }

    /** @return array{extension: string, width: int, height: int, durationMs: null} */
    private function image(string $path, string $mime): array
    {
        $formats = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($formats[$mime])) {
            throw new ApiProblem(422, '/errors/post-media-format', 'Use uma imagem JPEG, PNG ou WebP.');
        }
        $info = @getimagesize($path);
        if (false === $info || $info['mime'] !== $mime) {
            throw new ApiProblem(422, '/errors/post-media-corrupt', 'A imagem está corrompida ou incompleta.');
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::IMAGE_MAX_PIXELS) {
            throw new ApiProblem(422, '/errors/post-media-dimensions', 'A imagem tem dimensões grandes demais.');
        }
        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            $head = file_get_contents($path, false, null, 0, 1024 * 1024);
            if (is_string($head) && (('image/png' === $mime && str_contains($head, 'acTL')) || ('image/webp' === $mime && str_contains($head, 'ANIM')))) {
                throw new ApiProblem(422, '/errors/post-media-animation', 'Use uma imagem estática.');
            }
        }

        return ['extension' => $formats[$mime], 'width' => $width, 'height' => $height, 'durationMs' => null];
    }

    /** @return array{extension: string, width: int, height: int, durationMs: int} */
    private function video(string $path, string $mime): array
    {
        $formats = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov'];
        if (!isset($formats[$mime])) {
            throw new ApiProblem(422, '/errors/post-media-format', 'Use um vídeo MP4 ou MOV compatível.');
        }
        $process = @proc_open(['timeout', '--signal=KILL', '20s', 'prlimit', '--as=268435456', '--cpu=20', '--', 'ffprobe', '-v', 'error', '-show_entries', 'format=duration:stream=codec_type,codec_name,width,height', '-of', 'json', $path], [
            0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw ApiProblem::serviceUnavailable();
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if (0 !== $exit) {
            if (in_array($exit, [124, 127], true)) {
                throw ApiProblem::serviceUnavailable();
            }
            throw new ApiProblem(422, '/errors/post-media-corrupt', 'O vídeo está corrompido ou incompleto.');
        }
        $data = json_decode($stdout, true);
        $video = null;
        $audioCodecs = [];
        foreach (($data['streams'] ?? []) as $stream) {
            if ('video' === ($stream['codec_type'] ?? null)) {
                $video = $stream;
            } elseif ('audio' === ($stream['codec_type'] ?? null) && is_string($stream['codec_name'] ?? null)) {
                $audioCodecs[] = $stream['codec_name'];
            }
        }
        $codec = $video['codec_name'] ?? null;
        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);
        $durationMs = (int) round(((float) ($data['format']['duration'] ?? 0)) * 1000);
        if (null === $video || !in_array($codec, ['h264', 'hevc'], true)) {
            throw new ApiProblem(422, '/errors/post-media-codec', 'Use um vídeo H.264 ou HEVC compatível.');
        }
        if ([] !== array_diff($audioCodecs, ['aac', 'mp3'])) {
            throw new ApiProblem(422, '/errors/post-media-codec', 'Use áudio AAC ou MP3 no vídeo.');
        }
        if ($width < 1 || $height < 1 || $durationMs < 1) {
            throw new ApiProblem(422, '/errors/post-media-corrupt', 'Não foi possível ler o vídeo.');
        }
        if ($durationMs > self::VIDEO_MAX_DURATION_MS) {
            throw new ApiProblem(422, '/errors/post-media-duration', 'O vídeo pode ter no máximo 30 segundos.');
        }

        return ['extension' => $formats[$mime], 'width' => $width, 'height' => $height, 'durationMs' => $durationMs];
    }
}
