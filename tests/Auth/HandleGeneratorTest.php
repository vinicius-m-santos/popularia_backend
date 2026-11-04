<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\HandleGenerator;
use App\Entity\User;
use App\Repository\UserRepository;
use PHPUnit\Framework\TestCase;

final class HandleGeneratorTest extends TestCase
{
    public function testGeneratesTwoReadableWordsWithinTheHandleLimit(): void
    {
        $generator = new HandleGenerator($this->createMock(UserRepository::class));

        for ($i = 0; $i < 40; ++$i) {
            $handle = $generator->generateAvailable(static fn (string $candidate): bool => false);

            self::assertMatchesRegularExpression(User::HANDLE_PATTERN, $handle);
            self::assertGreaterThanOrEqual(User::HANDLE_MIN_LENGTH, strlen($handle));
            self::assertLessThanOrEqual(User::HANDLE_MAX_LENGTH, strlen($handle));
        }
    }

    public function testAvoidsExistingHandles(): void
    {
        $generator = new HandleGenerator($this->createMock(UserRepository::class));
        $taken = [];

        $handle = $generator->generateAvailable(static function (string $candidate) use (&$taken): bool {
            $taken[] = $candidate;

            return count($taken) < 3;
        });

        self::assertCount(3, $taken);
        self::assertSame($handle, $taken[2]);
    }
}
