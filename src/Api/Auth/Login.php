<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Constraint\TextRules;
use App\Entity\AuthSession;
use Symfony\Component\Validator\Constraints as Assert;

/** Login com e-mail e senha. Cria uma sessão para este aparelho. */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/auth/login',
            status: 200,
            output: Tokens::class,
            processor: LoginProcessor::class,
            openapi: new Operation(
                tags: ['Autenticação'],
                summary: 'Entra com e-mail e senha e cria uma sessão.',
                description: 'O erro é o mesmo (401 /errors/invalid-credentials) para e-mail inexistente e senha errada. Limite de taxa por IP e por IP+e-mail (429 com Retry-After). Contas com e-mail ainda não confirmado podem entrar; publicar exige a confirmação.',
            ),
        ),
    ],
)]
final class Login
{
    #[Assert\NotBlank(message: 'Informe seu e-mail.')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank(message: 'Informe a senha.')]
    #[Assert\Length(max: 4096)]
    public string $password = '';

    /** Nome do aparelho para a lista de sessões, como "iPhone". Opcional. */
    #[Assert\Length(max: AuthSession::DEVICE_LABEL_MAX_LENGTH)]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public ?string $deviceLabel = null;
}
