<?php

declare(strict_types=1);

namespace App\Api\PostMedia;

use App\Api\ApiProblem;
use App\PostMedia\PostMediaService;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Serializer\SerializerInterface;

#[AsController]
final readonly class UploadController
{
    public function __construct(private PostMediaService $service, private CurrentUser $user, private SerializerInterface $serializer)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->request->get('metadata');
        if (!is_string($raw) || strlen($raw) > 1000) {
            throw new ApiProblem(422, '/errors/post-media-metadata', 'Confira os dados da mídia.');
        }
        $metadata = json_decode($raw, true);
        if (!is_array($metadata) || !is_string($metadata['clientId'] ?? null) || !is_string($metadata['kind'] ?? null) || array_diff(array_keys($metadata), ['clientId', 'kind'])) {
            throw new ApiProblem(422, '/errors/post-media-metadata', 'Confira os dados da mídia.');
        }
        $file = $request->files->get('file');
        if (null !== $file && !$file instanceof UploadedFile) {
            $file = null;
        }
        $media = $this->service->upload($this->user->get(), $metadata['clientId'], $metadata['kind'], $file);
        $view = new PostMediaView($media->id->toRfc4122(), $media->kind, $media->mimeType, $media->byteSize, $media->width, $media->height, $media->durationMs);

        return new JsonResponse($this->serializer->serialize($view, 'json'), 201, [], true);
    }
}
