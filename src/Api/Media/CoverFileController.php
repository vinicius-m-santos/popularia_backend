<?php

declare(strict_types=1);

namespace App\Api\Media;

use App\Api\ApiProblem;
use App\Entity\Comuna;
use App\Media\StoredImageResponder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;

#[AsController]
final readonly class CoverFileController
{
    public function __construct(private EntityManagerInterface $em, private StoredImageResponder $images)
    {
    }

    public function __invoke(string $comunaId, string $fileId): Response
    {
        if (!Uuid::isValid($comunaId) || !Uuid::isValid($fileId)) {
            throw ApiProblem::notFound();
        }
        $comuna = $this->em->find(Comuna::class, Uuid::fromString($comunaId));

        return $this->images->stream($comuna?->getCoverKey(), 'comuna-covers', $fileId);
    }
}
