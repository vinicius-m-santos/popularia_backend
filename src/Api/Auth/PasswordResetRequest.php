<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Accepted;
use Symfony\Component\Validator\Constraints as Assert;

/** Pedido de código para criar uma nova senha. */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/auth/password-reset/request',
            status: 202,
            output: Accepted::class,
            processor: PasswordResetRequestProcessor::class,
            openapi: new Operation(
                tags: ['Autenticação'],
                summary: 'Envia um código de recuperação de senha, se houver conta com o e-mail.',
                description: 'Sempre 202 com a mesma mensagem, para não revelar quais e-mails têm conta. O código vale 30 minutos e é de uso único.',
            ),
        ),
    ],
)]
final class PasswordResetRequest
{
    #[Assert\NotBlank(message: 'Informe seu e-mail.', normalizer: 'trim')]
    #[Assert\Email(message: 'Confira o e-mail: falta algo como “@” ou “.com”.', normalizer: 'trim')]
    #[Assert\Length(max: 180)]
    public string $email = '';
}
