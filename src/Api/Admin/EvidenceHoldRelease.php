<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Entity\EvidenceHold;
use Symfony\Component\Validator\Constraints as Assert;

final class EvidenceHoldRelease
{
    #[Assert\NotBlank(message: 'Informe o motivo da liberação.')]
    #[Assert\Length(min: EvidenceHold::REASON_MIN_LENGTH, max: EvidenceHold::REASON_MAX_LENGTH)]
    public string $reason = '';
}
