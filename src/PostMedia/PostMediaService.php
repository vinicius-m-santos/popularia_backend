<?php

declare(strict_types=1);

namespace App\PostMedia;

use App\Api\ApiProblem;
use App\Entity\PostMedia;
use App\Entity\User;
use App\Library\ObjectStorage;
use App\Security\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final readonly class PostMediaService
{
    public function __construct(private PostMediaValidator $validator, private ObjectStorage $storage, private EntityManagerInterface $em, private ClockInterface $clock, private RateLimitGuard $rateLimit)
    {
    }

    public function upload(User $actor, string $clientId, string $kind, ?UploadedFile $upload): PostMedia
    {
        if (!$actor->isEmailVerified()) {
            throw ApiProblem::forbidden('/errors/email-not-verified', 'Confirme seu e-mail para publicar.');
        }
        if (!Uuid::isValid($clientId)) {
            throw ApiProblem::badRequest('/errors/post-media-upload-id', 'Identifique o envio para tentar novamente com segurança.');
        }
        $info = $this->validator->validate($upload, $kind);
        $existing = $this->em->getRepository(PostMedia::class)->findOneBy(['uploadedBy' => $actor, 'clientId' => $clientId]);
        if (null !== $existing && 'ready' === $existing->status) {
            if (!hash_equals($existing->checksum, $info['checksum'])) {
                throw ApiProblem::conflict('/errors/post-media-file-changed', 'O arquivo mudou. Selecione-o novamente.');
            }

            return $existing;
        }
        $this->rateLimit->consume('post_media_upload', $actor->getUserIdentifier());

        $media = $this->em->getConnection()->transactional(function () use ($actor, $clientId, $info): PostMedia {
            $this->em->getConnection()->executeQuery('SELECT id FROM app_user WHERE id = ? FOR NO KEY UPDATE', [$actor->getId()->toRfc4122()])->fetchOne();
            $existing = $this->em->getRepository(PostMedia::class)->findOneBy(['uploadedBy' => $actor, 'clientId' => $clientId]);
            if (null === $existing) {
                $media = new PostMedia($actor, $clientId, 'post-media/'.Uuid::v7()->toRfc4122().'.'.$info['extension'], $info['kind'], $info['mimeType'], $info['checksum'], $info['byteSize'], $info['width'], $info['height'], $info['durationMs'], $this->clock->now());
                $this->em->persist($media);
            } else {
                if ('ready' === $existing->status) {
                    if (!hash_equals($existing->checksum, $info['checksum'])) {
                        throw ApiProblem::conflict('/errors/post-media-file-changed', 'O arquivo mudou. Selecione-o novamente.');
                    }

                    return $existing;
                }
                if ('pending' === $existing->status) {
                    throw ApiProblem::conflict('/errors/post-media-upload-pending', 'Este envio já está em andamento.');
                }
                if (!hash_equals($existing->checksum, $info['checksum'])) {
                    throw ApiProblem::conflict('/errors/post-media-file-changed', 'O arquivo mudou. Selecione-o novamente.');
                }
                $media = $existing;
                $media->status = 'pending';
                $media->createdAt = $this->clock->now();
            }
            $this->em->flush();

            return $media;
        });

        if ('ready' === $media->status) {
            return $media;
        }

        try {
            \assert(null !== $upload);
            $this->storage->put($media->storageKey, $upload->getPathname(), $media->checksum, $media->mimeType);
            $media->status = 'ready';
            $this->em->flush();

            return $media;
        } catch (\Throwable $error) {
            $this->em->getConnection()->executeStatement("UPDATE post_media SET status = 'failed' WHERE id = ? AND status <> 'ready'", [$media->id->toRfc4122()]);
            try {
                $this->storage->delete($media->storageKey);
            } catch (\Throwable) {
            }
            throw $error;
        }
    }
}
