<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Entity\EvidenceHold;
use Symfony\Component\Validator\Constraints as Assert;

final class EvidenceHoldInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $userId = '';

    #[Assert\NotBlank(message: 'Informe a justificativa.')]
    #[Assert\Length(min: EvidenceHold::REASON_MIN_LENGTH, max: EvidenceHold::REASON_MAX_LENGTH)]
    public string $reason = '';
}
