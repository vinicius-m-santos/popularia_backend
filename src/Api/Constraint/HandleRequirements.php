<?php

declare(strict_types=1);

namespace App\Api\Constraint;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/** Nome de usuário (@): 3 a 20 caracteres entre a-z, 0-9, ponto e sublinhado, fora da lista reservada. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class HandleRequirements extends Compound
{
    /** Nomes que poderiam se passar pela plataforma ou pela moderação. */
    public const array RESERVED = [
        'popularia', 'admin', 'administracao', 'moderacao', 'moderador', 'moderadora', 'suporte',
        'ajuda', 'equipe', 'oficial', 'api', 'root', 'sistema', 'seguranca', 'contato', 'me',
    ];

    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(message: 'Escolha um nome de usuário.'),
            new Assert\Regex(
                pattern: User::HANDLE_PATTERN,
                message: 'Use de 3 a 20 caracteres: letras minúsculas, números, ponto ou sublinhado.',
            ),
            new Assert\Choice(choices: self::RESERVED, match: false, message: 'Esse nome já está em uso.'),
        ];
    }
}
