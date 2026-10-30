<?php

declare(strict_types=1);

namespace App\Api\Moderation;

use App\Api\Constraint\TextRules;
use Symfony\Component\Validator\Constraints as Assert;

/** Decisão de moderação. A nota é interna e não volta para quem foi denunciado. */
final class ReportDecision
{
    #[Assert\Choice(choices: ['claim', 'dismiss', 'remove', 'remove_and_escalate', 'escalate', 'restore', 'suspend', 'reactivate', 'suspend_comuna', 'reactivate_comuna'])]
    public string $action = '';

    #[Assert\Length(max: 800)]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public string $note = '';
}
