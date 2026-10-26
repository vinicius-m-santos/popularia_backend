<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use Symfony\Component\Validator\Constraints as Assert;

/** Troca um refresh token válido por um novo par de tokens (rotação a cada uso). */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/auth/refresh',
            status: 200,
            output: Tokens::class,
            processor: TokenRefreshProcessor::class,
            openapi: new Operation(
                tags: ['Autenticação'],
                summary: 'Renova a sessão: devolve um novo access token e um novo refresh token.',
                description: 'O refresh token enviado deixa de valer. Se um refresh token já usado for apresentado de novo, a sessão inteira é encerrada (possível roubo do token) e a resposta é 401 /errors/invalid-refresh-token.',
            ),
        ),
    ],
)]
final class TokenRefresh
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    public string $refreshToken = '';
}
