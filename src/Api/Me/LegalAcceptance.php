<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Legal\LegalDocuments;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(operations: [new Post(uriTemplate: '/me/legal-acceptance', security: "is_granted('ROLE_USER')", processor: LegalAcceptanceProcessor::class, output: Me::class)])]
final class LegalAcceptance
{
    #[Assert\IdenticalTo(value: LegalDocuments::VERSION, message: 'Leia a versão atual dos documentos.')]
    public string $version = '';

    #[Assert\IsTrue(message: 'Confirme que leu e aceita os Termos e as Regras da Comunidade.')]
    public bool $accepted = false;
}
