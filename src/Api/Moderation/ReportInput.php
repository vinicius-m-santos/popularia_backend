<?php

declare(strict_types=1);

namespace App\Api\Moderation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(operations: [new Post(uriTemplate: '/reports', status: 204, output: false, security: "is_granted('ROLE_USER')", processor: ReportProcessor::class, openapi: new Operation(tags: ['Moderação'], summary: 'Denuncia publicação, comentário ou perfil visível; fila operacional da plataforma.'))])]
final class ReportInput
{
    #[Assert\Choice(choices: ['post', 'comment', 'profile'])]
    public string $targetType = '';

    #[Assert\Uuid]
    public string $targetId = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    public string $reason = '';
}
