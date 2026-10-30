<?php

declare(strict_types=1);

namespace App\Api\Media;

use App\Api\ApiProblem;
use App\Entity\User;
use App\Media\StoredImageResponder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;

#[AsController]
final readonly class AvatarFileController
{
    public function __construct(private EntityManagerInterface $em, private StoredImageResponder $images)
    {
    }

    public function __invoke(string $userId, string $fileId): Response
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($fileId)) {
            throw ApiProblem::notFound();
        }
        $user = $this->em->find(User::class, Uuid::fromString($userId));
        if (null === $user || !$user->isActive()) {
            throw ApiProblem::notFound();
        }

        return $this->images->stream($user->getAvatarKey(), 'avatars', $fileId);
    }
}
