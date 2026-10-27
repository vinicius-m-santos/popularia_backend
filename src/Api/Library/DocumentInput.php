<?php

declare(strict_types=1);

namespace App\Api\Library;

use App\Api\Constraint\TextRules;
use App\Enum\LibraryAccess;
use App\Enum\Topic;
use Symfony\Component\Validator\Constraints as Assert;

final class DocumentInput
{
    #[Assert\NotBlank(groups: ['upload'])]
    #[Assert\Length(min: 1, max: 160)]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public ?string $title = null;

    #[Assert\Length(max: 1000)]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public ?string $description = null;

    #[Assert\NotBlank(groups: ['upload'])]
    #[Assert\Uuid]
    public ?string $collectionId = null;

    #[Assert\Choice(callback: [Topic::class, 'values'])]
    public ?string $topic = null;

    public bool $clearTopic = false;

    #[Assert\Choice(callback: [LibraryAccess::class, 'values'])]
    public ?string $access = null;

    public ?bool $isPublic = null;

    #[Assert\NotBlank(groups: ['upload'])]
    #[Assert\Uuid]
    public ?string $clientId = null;

    /** @var list<string> */
    #[Assert\Count(max: 20)]
    #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[a-z0-9-]{1,100}$/')])]
    public array $sharedWith = [];
}
