<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\UserAuthIdentity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Vinculação de identity provider à conta já autenticada.
 * Listagem GET e adição POST. Remoção (DELETE) não implementada no beta.
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/me/auth-identities',
            output: AuthIdentitiesView::class,
            provider: AuthIdentitiesProvider::class,
            openapi: new Operation(
                tags: ['Conta'],
                summary: 'Lista os métodos de autenticação vinculados.',
            ),
            security: 'is_granted("ROLE_USER")',
        ),
        new Post(
            uriTemplate: '/me/auth-identities',
            status: 200,
            output: AuthIdentitiesView::class,
            processor: AuthIdentityLinkProcessor::class,
            openapi: new Operation(
                tags: ['Conta'],
                summary: 'Vincula um provider (Google ou Apple) à conta autenticada.',
                description: 'Requer que o usuário já esteja logado. Valida o token do provider e cria a identidade vinculada.',
            ),
            security: 'is_granted("ROLE_USER")',
        ),
    ],
)]
final class AuthIdentityLink
{
    #[Assert\NotBlank]
    #[Assert\Choice(choices: UserAuthIdentity::VALID_PROVIDERS)]
    public string $provider = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 8192)]
    public string $idToken = '';

    #[Assert\Length(max: 512)]
    public ?string $nonce = null;
}
