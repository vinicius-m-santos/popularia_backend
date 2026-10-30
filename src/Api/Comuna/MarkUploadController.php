<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use App\Media\ProfileImageService;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Serializer\SerializerInterface;

#[AsController]
final readonly class MarkUploadController
{
    public function __construct(
        private ProfileImageService $images,
        private CurrentUser $current,
        private ComunaViews $views,
        private SerializerInterface $serializer,
    ) {
    }

    public function __invoke(Request $request, string $slug): JsonResponse
    {
        $file = $request->files->get('file');
        $comuna = $this->images->replaceMark($slug, $this->current->get(), $file instanceof UploadedFile ? $file : null);
        $view = $this->views->detail($comuna, $this->current->get());

        return new JsonResponse($this->serializer->serialize($view, 'json'), 200, [], true);
    }
}
