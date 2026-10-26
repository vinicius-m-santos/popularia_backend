<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use App\Api\Constraint\TextRules;
use App\Entity\Comuna;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * O que o proprietário pode mudar: descrição e regras. Null = não mudar. Nome, endereço,
 * visibilidade e proprietário não estão aqui (enviá-los é campo desconhecido: 400).
 */
final class ComunaUpdateInput
{
    #[Assert\NotBlank(message: 'Conte em poucas palavras para que serve a comuna.', allowNull: true, normalizer: 'trim')]
    #[Assert\Length(max: Comuna::DESCRIPTION_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public ?string $description = null;

    /** @var list<ComunaRuleInput>|null A lista inteira, na ordem; substitui a anterior. */
    #[Assert\Count(max: Comuna::MAX_RULES, maxMessage: 'Use até {{ limit }} regras.')]
    #[Assert\Valid]
    public ?array $rules = null;
}
