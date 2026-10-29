<?php

declare(strict_types=1);

namespace App\Api\Notification;

use Symfony\Component\Validator\Constraints as Assert;

final class PushDeviceInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Assert\Regex('/^(ExpoPushToken|ExponentPushToken)\[[A-Za-z0-9_-]{10,200}\]$/D')]
    public string $token = '';

    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-f0-9-]{36,128}$/Di')]
    #[Assert\Length(min: 36, max: 128)]
    public string $revocationSecret = '';

    #[Assert\Choice(choices: ['ios', 'android'])]
    public string $platform = '';
}
