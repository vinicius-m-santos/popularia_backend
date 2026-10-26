<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;

/** Encerrar sessões no servidor. O app também apaga os tokens guardados no aparelho. */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/auth/logout',
            status: 204,
            input: false,
            output: false,
            security: "is_granted('ROLE_USER')",
            processor: LogoutProcessor::class,
            openapi: new Operation(
                tags: ['Autenticação'],
                summary: 'Sai deste aparelho: encerra a sessão atual.',
                description: 'O access token e o refresh token desta sessão deixam de valer imediatamente.',
            ),
        ),
        new Post(
            uriTemplate: '/auth/logout-all',
            status: 204,
            input: false,
            output: false,
            security: "is_granted('ROLE_USER')",
            processor: LogoutAllProcessor::class,
            openapi: new Operation(
                tags: ['Autenticação'],
                summary: 'Sai de todos os aparelhos: encerra todas as sessões da conta.',
            ),
        ),
    ],
)]
final class Logout
{
}
