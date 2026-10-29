<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use Symfony\Component\Validator\Constraints as Assert;

/** Revocation-only capability: allows an offline logout to be completed without an account session. */
#[ApiResource(operations: [new Post(uriTemplate: '/push-devices/{id}/revocation', status: 204, output: false, processor: PushDeviceRevocationProcessor::class)])]
final class PushDeviceRevocation
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 36, max: 128)]
    public string $revocationSecret = '';
}
