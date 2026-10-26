<?php

declare(strict_types=1);

namespace App\Api\Constraint;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Regra de senha: pelo menos 8 caracteres, sem exigir símbolos ou maiúsculas (frases longas
 * são bem-vindas). O limite superior só evita abuso do algoritmo de hash.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class PasswordRequirements extends Compound
{
    public const int MIN_LENGTH = 8;

    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(message: 'Informe a senha.'),
            new Assert\Length(
                min: self::MIN_LENGTH,
                max: 4096,
                minMessage: 'A senha precisa de pelo menos {{ limit }} caracteres.',
                maxMessage: 'A senha é longa demais.',
            ),
        ];
    }
}
