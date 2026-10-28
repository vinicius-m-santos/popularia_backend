<?php
declare(strict_types=1);
namespace App\Api\Support;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use Symfony\Component\Validator\Constraints as Assert;
#[ApiResource(operations: [new Post(uriTemplate: '/support/contact', status: 202, output: false, security: "is_granted('ROLE_USER')", processor: ContactMessageProcessor::class, openapi: new Operation(tags: ['Suporte'], summary: 'Envia uma mensagem ao suporte.'))])]
final class ContactMessage
{
    #[Assert\NotBlank(message: 'Escreva uma mensagem.')]
    #[Assert\Length(max: 5000, maxMessage: 'Use até {{ limit }} caracteres.')]
    public string $message = '';
}
