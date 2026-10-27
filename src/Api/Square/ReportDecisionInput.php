<?php

declare(strict_types=1);

namespace App\Api\Square;

use Symfony\Component\Validator\Constraints as Assert;

final class ReportDecisionInput
{
    #[Assert\Choice(choices: ['dismissed', 'removed'])]
    public string $status = '';
}
