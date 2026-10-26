<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Accepted;
use Symfony\Component\Validator\Constraints as Assert;

/** Confirmação do e-mail com o código de 6 dígitos enviado no cadastro. */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/me/email-verification',
            status: 204,
            output: false,
            security: "is_granted('ROLE_USER')",
            processor: EmailVerificationProcessor::class,
            openapi: new Operation(
                tags: ['Conta'],
                summary: 'Confirma o e-mail da conta com o código recebido.',
                description: 'Código errado, usado ou vencido: 400 /errors/invalid-code. Depois de 5 erros o código deixa de valer e é preciso pedir outro. Se o e-mail já estiver confirmado, responde 204.',
            ),
        ),
        new Post(
            uriTemplate: '/me/email-verification/resend',
            status: 202,
            input: false,
            output: Accepted::class,
            security: "is_granted('ROLE_USER')",
            processor: EmailVerificationResendProcessor::class,
            openapi: new Operation(tags: ['Conta'], summary: 'Envia um novo código de confirmação (o anterior deixa de valer).'),
        ),
    ],
)]
final class EmailVerification
{
    #[Assert\NotBlank(message: 'Informe o código.')]
    #[Assert\Regex(pattern: '/^\d{6}$/', message: 'O código tem 6 números.')]
    public string $code = '';
}
