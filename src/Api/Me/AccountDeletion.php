<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(operations: [new Post(uriTemplate: '/me/account-deletion', status: 204, output: false, security: "is_granted('ROLE_USER')", processor: AccountDeletionProcessor::class, openapi: new Operation(tags: ['Conta'], summary: 'Exclui a própria conta e invalida todas as sessões.', description: 'Confirmação EXCLUIR e reautenticação por senha ou identidade social vinculada. Contas Apple exigem também código recente para revogar a autorização. Comunas ativas precisam ser transferidas ou arquivadas.'))])]
final class AccountDeletion
{
    #[Assert\IdenticalTo(value: 'EXCLUIR')]
    public string $confirmation = '';

    #[Assert\Length(max: 4096)]
    public ?string $password = null;

    #[Assert\Choice(choices: ['google', 'apple'])]
    public ?string $provider = null;

    #[Assert\Length(max: 8192)]
    public ?string $idToken = null;

    #[Assert\Length(max: 512)]
    public ?string $nonce = null;

    #[Assert\Length(max: 4096)]
    public ?string $authorizationCode = null;
}
