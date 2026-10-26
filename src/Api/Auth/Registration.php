<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Accepted;
use App\Api\Constraint\HandleRequirements;
use App\Api\Constraint\PasswordRequirements;
use App\Api\Constraint\TextRules;
use App\Entity\User;
use App\Enum\Topic;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Cadastro. Responde sempre 202 com a mesma mensagem, exista ou não uma conta com o e-mail:
 * se já existir, quem é dono do e-mail recebe um aviso. O @ é público, então "@ em uso" é 422.
 * Depois do 202 o app entra com e-mail e senha (POST /auth/login) e pede o código de e-mail.
 */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/auth/register',
            status: 202,
            output: Accepted::class,
            processor: RegistrationProcessor::class,
            openapi: new Operation(
                tags: ['Autenticação'],
                summary: 'Cria uma conta e envia um código de confirmação por e-mail.',
                description: 'Sempre 202 quando os dados são válidos, para não revelar quais e-mails têm conta. 422 se os dados forem inválidos ou o @ já estiver em uso. Limite de taxa por IP.',
            ),
        ),
    ],
)]
final class Registration
{
    #[Assert\NotBlank(message: 'Informe seu e-mail.', normalizer: 'trim')]
    #[Assert\Email(message: 'Confira o e-mail: falta algo como “@” ou “.com”.', normalizer: 'trim')]
    #[Assert\Length(max: 180, maxMessage: 'O e-mail é longo demais.')]
    public string $email = '';

    #[PasswordRequirements]
    public string $password = '';

    #[HandleRequirements]
    public string $handle = '';

    #[Assert\NotBlank(message: 'Preencha seu nome.')]
    #[Assert\Length(max: User::DISPLAY_NAME_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public string $displayName = '';

    #[Assert\Length(max: User::BIO_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public ?string $bio = null;

    #[Assert\Length(max: User::CITY_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public ?string $city = null;

    /** @var list<string> */
    #[Assert\Count(max: 18)]
    #[Assert\All([new Assert\Choice(callback: [Topic::class, 'values'], message: 'Assunto desconhecido.')])]
    public array $interests = [];
}
