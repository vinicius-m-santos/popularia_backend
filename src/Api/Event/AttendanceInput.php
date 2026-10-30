<?php

declare(strict_types=1);

namespace App\Api\Event;

use App\Enum\AttendanceStatus;
use Symfony\Component\Validator\Constraints as Assert;

final class AttendanceInput
{
    #[Assert\NotBlank(message: 'Diga se você vai.')]
    #[Assert\Choice(callback: [AttendanceStatus::class, 'values'], message: 'Resposta desconhecida.')]
    public string $status = '';
}
