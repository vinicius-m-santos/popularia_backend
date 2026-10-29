<?php

declare(strict_types=1);

namespace App\Media;

use App\Api\ApiProblem;
use App\Content\ComunaModerationPolicy;
use App\Entity\Comuna;
use App\Entity\User;
use App\Library\ObjectStorage;
use App\PostMedia\PostMediaValidator;
use App\Repository\ComunaRepository;
use App\Security\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/** Foto de perfil e capa de comuna no mesmo storage e com a mesma validação das imagens de publicação. */
final readonly class ProfileImageService
{
    public function __construct(
        private PostMediaValidator $validator,
        private ObjectStorage $storage,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private RateLimitGuard $rateLimit,
        private ComunaRepository $comunas,
        private ComunaModerationPolicy $policy,
    ) {
    }

    public function replaceAvatar(User $user, ?UploadedFile $file): User
    {
        $info = $this->prepare($user, $file);
        $key = 'avatars/'.Uuid::v7()->toRfc4122().'.'.$info['extension'];
        $previous = $user->getAvatarKey();
        $this->store($key, $file, $info);
        $user->replaceAvatar($key, $this->clock->now());
        $this->flushOrDelete($key);
        $this->deleteQuietly($previous);

        return $user;
    }

    public function replaceCover(string $slug, User $actor, ?UploadedFile $file): Comuna
    {
        $comuna = $this->comunas->findOneBySlug($slug) ?? throw ApiProblem::notFound('Comuna não encontrada.');
        $this->policy->assertOwner($comuna, $actor);
        $this->policy->assertNotArchived($comuna);
        $info = $this->prepare($actor, $file);
        $key = 'comuna-covers/'.Uuid::v7()->toRfc4122().'.'.$info['extension'];
        $previous = $comuna->getCoverKey();
        $this->store($key, $file, $info);
        $comuna->replaceCover($key);
        $this->flushOrDelete($key);
        $this->deleteQuietly($previous);

        return $comuna;
    }

    /**
     * @return array{extension: string, checksum: string, mimeType: string}
     */
    private function prepare(User $actor, ?UploadedFile $file): array
    {
        $this->rateLimit->consume('profile_image', $actor->getUserIdentifier());
        $info = $this->validator->validate($file, 'image');

        return ['extension' => $info['extension'], 'checksum' => $info['checksum'], 'mimeType' => $info['mimeType']];
    }

    /** @param array{extension: string, checksum: string, mimeType: string} $info */
    private function store(string $key, ?UploadedFile $file, array $info): void
    {
        \assert($file instanceof UploadedFile);
        $this->storage->put($key, $file->getPathname(), $info['checksum'], $info['mimeType']);
    }

    private function flushOrDelete(string $key): void
    {
        try {
            $this->em->flush();
        } catch (\Throwable $error) {
            $this->deleteQuietly($key);
            throw $error;
        }
    }

    private function deleteQuietly(?string $key): void
    {
        if (null === $key) {
            return;
        }
        try {
            $this->storage->delete($key);
        } catch (\Throwable) {
        }
    }
}
