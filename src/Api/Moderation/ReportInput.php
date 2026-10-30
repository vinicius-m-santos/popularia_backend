<?php

declare(strict_types=1);

namespace App\Api\Moderation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Constraint\TextRules;
use App\Enum\ReportReason;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(operations: [new Post(
    uriTemplate: '/reports',
    status: 204,
    output: false,
    security: "is_granted('ROLE_USER')",
    processor: ReportProcessor::class,
    openapi: new Operation(
        tags: ['Moderação'],
        summary: 'Denuncia publicação, comentário, perfil ou comuna. Não remove nada sozinha. Denúncia repetida e ainda aberta é ignorada.',
    ),
)])]
final class ReportInput
{
    #[Assert\Choice(choices: ['post', 'comment', 'profile', 'comuna'])]
    public string $targetType = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    public string $targetId = '';

    #[Assert\Choice(callback: [ReportReason::class, 'values'])]
    public string $reasonCode = '';

    #[Assert\Length(max: 800)]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public string $description = '';
}
