<?php

declare(strict_types=1);

namespace App\Tests\Social;

use App\Api\ApiProblem;
use App\Social\AppleAuthorizationRevoker;

final class FakeAppleAuthorizationRevoker extends AppleAuthorizationRevoker
{
    public bool $fail = false;
    public int $calls = 0;

    public function __construct()
    {
    }

    public function revokeCode(#[\SensitiveParameter] string $code, string $subject): void
    {
        ++$this->calls;
        if ($this->fail || '' === $code) {
            throw ApiProblem::serviceUnavailable();
        }
    }
}
