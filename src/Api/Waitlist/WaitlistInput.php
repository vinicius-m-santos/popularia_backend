<?php

declare(strict_types=1);

namespace App\Api\Waitlist;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/waitlist',
            processor: WaitlistProcessor::class,
            output: WaitlistOutput::class,
            openapi: new Operation(
                tags: ['Lista de espera'],
                summary: 'Inscreve um email na lista de espera do Popularia.',
            ),
        ),
    ],
)]
final class WaitlistInput
{
    #[Assert\NotBlank(message: 'Informe um e-mail.')]
    #[Assert\Email(message: 'E-mail inválido.')]
    #[Assert\Length(max: 320)]
    public string $email = '';
}
