<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Constraint\PasswordRequirements;
use Symfony\Component\Validator\Constraints as Assert;

/** Nova senha com o código recebido por e-mail. Encerra todas as sessões da conta. */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/auth/password-reset/confirm',
            status: 204,
            output: false,
            processor: PasswordResetConfirmationProcessor::class,
            openapi: new Operation(
                tags: ['Autenticação'],
                summary: 'Define uma nova senha com o código de recuperação.',
                description: 'Encerra todas as sessões existentes (recuperação de conta comprometida). Código inválido, usado, vencido ou de outro e-mail: 400 /errors/invalid-code. Depois de 5 erros o código deixa de valer.',
            ),
        ),
    ],
)]
final class PasswordResetConfirmation
{
    #[Assert\NotBlank(message: 'Informe seu e-mail.')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank(message: 'Informe o código.')]
    #[Assert\Regex(pattern: '/^\d{6}$/', message: 'O código tem 6 números.')]
    public string $code = '';

    #[PasswordRequirements]
    public string $newPassword = '';
}
