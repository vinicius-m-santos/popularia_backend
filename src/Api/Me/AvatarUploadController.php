<?php

declare(strict_types=1);

namespace App\Api\Me;

use App\Media\ProfileImageService;
use App\Repository\UserFollowRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Serializer\SerializerInterface;

#[AsController]
final readonly class AvatarUploadController
{
    public function __construct(
        private ProfileImageService $images,
        private CurrentUser $current,
        private UserFollowRepository $follows,
        private SerializerInterface $serializer,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        $user = $this->images->replaceAvatar($this->current->get(), $file instanceof UploadedFile ? $file : null);
        $counts = $this->follows->socialCounts($user);
        $me = Me::fromUser($user, $counts['followers'], $counts['following']);

        return new JsonResponse($this->serializer->serialize($me, 'json'), 200, [], true);
    }
}
