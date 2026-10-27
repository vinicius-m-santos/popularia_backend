<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Constraint\HandleRequirements;
use App\Api\Constraint\TextRules;
use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Finaliza o onboarding de contas criadas via social login.
 * Disponível apenas quando `pendingOnboarding = true` na conta.
 */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/me/complete-onboarding',
            status: 200,
            output: Me::class,
            processor: CompleteOnboardingProcessor::class,
            openapi: new Operation(
                tags: ['Conta'],
                summary: 'Finaliza onboarding de conta social (escolha de handle e nome).',
                description: 'Disponível apenas para contas em estado `pendingOnboarding`. Após este endpoint o flag é removido.',
            ),
            security: 'is_granted("ROLE_USER")',
        ),
    ],
)]
final class CompleteOnboarding
{
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

    /** @var list<string>|null */
    public ?array $interests = null;
}
