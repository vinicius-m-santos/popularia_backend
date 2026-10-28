<?php

declare(strict_types=1);

namespace App\Api\Me;

use App\Api\Constraint\HandleRequirements;
use App\Api\Constraint\TextRules;
use App\Entity\User;
use App\Enum\Topic;
use Symfony\Component\Validator\Constraints as Assert;

/** Campos que a pessoa pode mudar no próprio perfil. Null = não mudar; "" em bio/cidade = apagar. */
final class MeUpdate
{
    #[Assert\Length(min: User::HANDLE_MIN_LENGTH, max: User::HANDLE_MAX_LENGTH, minMessage: 'Use pelo menos {{ limit }} caracteres.', maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: User::HANDLE_PATTERN, message: 'Use de 3 a 20 caracteres: letras minúsculas, números, ponto ou sublinhado.')]
    #[Assert\Choice(choices: HandleRequirements::RESERVED, match: false, message: 'Esse nome já está em uso.')]
    public ?string $handle = null;
    public ?bool $isPrivate = null;

    #[Assert\NotBlank(message: 'Preencha seu nome.', allowNull: true)]
    #[Assert\Length(max: User::DISPLAY_NAME_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public ?string $displayName = null;

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
    public ?string $countryCode = null;

    #[Assert\Regex(pattern: '/^\\d{1,3}$/', message: 'Código de estado inválido.')]
    public ?string $stateIbgeCode = null;

    #[Assert\Regex(pattern: '/^\\d{7}$/', message: 'Código de município inválido.')]
    public ?string $municipalityIbgeCode = null;

    /** @var list<string>|null */
    #[Assert\Count(max: 18)]
    #[Assert\All([new Assert\Choice(callback: [Topic::class, 'values'], message: 'Assunto desconhecido.')])]
    public ?array $interests = null;
}
