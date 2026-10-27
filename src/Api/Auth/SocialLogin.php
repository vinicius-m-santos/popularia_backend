<?php

declare(strict_types=1);

namespace App\Api\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\AuthSession;
use App\Entity\UserAuthIdentity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Autenticação via provider social (Google ou Apple).
 * O mobile obtém o identity token do provider e envia aqui para validação.
 * O backend valida criptograficamente, nunca confia em campos enviados pelo app sem verificação.
 */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/auth/social',
            status: 200,
            output: SocialTokens::class,
            processor: SocialLoginProcessor::class,
            openapi: new Operation(
                tags: ['Autenticação'],
                summary: 'Autentica via Google ou Apple e cria uma sessão Popularia.',
                description: 'O backend valida o identity token criptograficamente. Nunca aceita payload autodeclarado sem verificação. Retorna os tokens da sessão Popularia mais o flag `onboardingRequired` para novas contas.',
            ),
        ),
    ],
)]
final class SocialLogin
{
    #[Assert\NotBlank]
    #[Assert\Choice(choices: UserAuthIdentity::VALID_PROVIDERS)]
    public string $provider = '';

    /** Identity token / ID token emitido pelo provider. */
    #[Assert\NotBlank]
    #[Assert\Length(max: 8192)]
    public string $idToken = '';

    /**
     * Nonce original (não hashado) gerado pelo mobile. Obrigatório para Apple.
     * O backend calcula sha256(nonce) e compara com o claim no token.
     */
    #[Assert\Length(max: 512)]
    public ?string $nonce = null;

    /** Nome completo retornado pelo provider na primeira autorização (Apple). */
    #[Assert\Length(max: 100)]
    public ?string $displayName = null;

    /** Nome do aparelho para a lista de sessões. */
    #[Assert\Length(max: AuthSession::DEVICE_LABEL_MAX_LENGTH)]
    public ?string $deviceLabel = null;
}
