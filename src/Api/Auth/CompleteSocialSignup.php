<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Constraint\HandleRequirements;
use App\Api\Constraint\TextRules;
use App\Entity\AuthSession;
use App\Entity\User;
use App\Enum\Topic;
use App\Legal\LegalDocuments;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Conclui o cadastro social pendente: cria a conta de verdade, grava o aceite
 * legal e só então inicia a sessão. O token é de uso único e expira em 24h.
 */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/auth/social/complete',
            status: 200,
            output: SocialTokens::class,
            processor: CompleteSocialSignupProcessor::class,
            openapi: new Operation(
                tags: ['Autenticação'],
                summary: 'Conclui um cadastro social pendente.',
                description: 'Exige o token temporário, os dados do perfil e o aceite legal. Cria a conta e inicia a sessão somente aqui. Token inválido/expirado retorna 400.',
            ),
        ),
    ],
)]
final class CompleteSocialSignup
{
    #[Assert\NotBlank(message: 'O cadastro pendente expirou. Entre novamente.')]
    #[Assert\Length(max: 128)]
    public string $pendingSignupToken = '';

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

    #[Assert\Length(max: User::STATE_MAX_LENGTH)]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public ?string $state = null;

    #[Assert\Length(max: User::COUNTRY_MAX_LENGTH)]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public ?string $country = null;

    #[Assert\Length(max: 2)]
    #[Assert\Regex(pattern: '/^[A-Za-z]{2}$/', message: 'Código de país inválido.')]
    public ?string $countryCode = 'BR';

    #[Assert\Regex(pattern: '/^\\d{1,3}$/', message: 'Código de estado inválido.')]
    public ?string $stateIbgeCode = null;

    #[Assert\Regex(pattern: '/^\\d{7}$/', message: 'Código de município inválido.')]
    public ?string $municipalityIbgeCode = null;

    /** @var list<string> */
    #[Assert\Count(max: 18)]
    #[Assert\All([new Assert\Choice(callback: [Topic::class, 'values'], message: 'Assunto desconhecido.')])]
    public array $interests = [];

    #[Assert\IsTrue(message: 'É necessário aceitar os Termos de Uso, as Regras da Comunidade e a Política de Privacidade.')]
    public bool $legalAccepted = false;

    #[Assert\Choice(choices: [LegalDocuments::VERSION], message: 'Os documentos legais foram atualizados. Leia novamente.')]
    public string $legalVersion = '';

    #[Assert\Length(max: AuthSession::DEVICE_LABEL_MAX_LENGTH)]
    public ?string $deviceLabel = null;
}
