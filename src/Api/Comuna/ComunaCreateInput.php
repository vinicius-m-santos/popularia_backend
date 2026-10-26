<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use App\Api\Constraint\TextRules;
use App\Entity\Comuna;
use App\Enum\Topic;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Dados de uma comuna nova. Proprietário, slug, estado, ano e contagens são do servidor:
 * campos desconhecidos são recusados (400).
 */
final class ComunaCreateInput
{
    #[Assert\NotBlank(message: 'Dê um nome para a comuna.', normalizer: 'trim')]
    #[Assert\Length(min: Comuna::NAME_MIN_LENGTH, max: Comuna::NAME_MAX_LENGTH, minMessage: 'Use pelo menos {{ limit }} caracteres.', maxMessage: 'Use até {{ limit }} caracteres.', normalizer: 'trim')]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public string $name = '';

    #[Assert\NotBlank(message: 'Conte em poucas palavras para que serve a comuna.', normalizer: 'trim')]
    #[Assert\Length(max: Comuna::DESCRIPTION_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public string $description = '';

    /** public: qualquer pessoa lê e entra; private: só membros leem, e a entrada depende de aprovação. */
    #[Assert\Choice(choices: ['public', 'private'], message: 'Escolha pública ou privada.')]
    public string $visibility = 'public';

    /** Lugar declarado, opcional ("São Paulo · SP"). */
    #[Assert\Length(max: Comuna::PLACE_MAX_LENGTH, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public string $place = '';

    #[Assert\Choice(choices: Comuna::TONES, message: 'Cor inválida.')]
    public string $tone = 'red';

    /** @var list<string> */
    #[Assert\Count(max: 3, maxMessage: 'Escolha até {{ limit }} assuntos.')]
    #[Assert\All([new Assert\Choice(callback: [Topic::class, 'values'], message: 'Assunto desconhecido.')])]
    public array $topics = [];

    /** @var list<ComunaRuleInput> */
    #[Assert\Count(max: Comuna::MAX_RULES, maxMessage: 'Use até {{ limit }} regras.')]
    #[Assert\Valid]
    public array $rules = [];
}
