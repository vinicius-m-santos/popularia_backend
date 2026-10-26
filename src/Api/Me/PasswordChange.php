<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Constraint\PasswordRequirements;
use Symfony\Component\Validator\Constraints as Assert;

/** Troca de senha de quem está logado. Exige a senha atual e encerra as outras sessões. */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/me/password',
            status: 204,
            output: false,
            security: "is_granted('ROLE_USER')",
            processor: PasswordChangeProcessor::class,
            openapi: new Operation(
                tags: ['Conta'],
                summary: 'Troca a senha. As outras sessões da conta são encerradas; esta continua.',
            ),
        ),
    ],
)]
final class PasswordChange
{
    #[Assert\NotBlank(message: 'Informe a senha atual.')]
    #[Assert\Length(max: 4096)]
    public string $currentPassword = '';

    #[PasswordRequirements]
    public string $newPassword = '';
}
