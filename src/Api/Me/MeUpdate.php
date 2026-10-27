<?php

declare(strict_types=1);

namespace App\Api\Me;

use App\Api\Constraint\TextRules;
use App\Entity\User;
use App\Enum\Topic;
use Symfony\Component\Validator\Constraints as Assert;

/** Campos que a pessoa pode mudar no próprio perfil. Null = não mudar; "" em bio/cidade = apagar. */
final class MeUpdate
{
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

    /** @var list<string>|null */
    #[Assert\Count(max: 18)]
    #[Assert\All([new Assert\Choice(callback: [Topic::class, 'values'], message: 'Assunto desconhecido.')])]
    public ?array $interests = null;
}
