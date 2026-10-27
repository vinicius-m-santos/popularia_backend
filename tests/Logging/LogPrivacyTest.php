<?php

declare(strict_types=1);

namespace App\Tests\Logging;

use App\Logging\LogContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class LogPrivacyTest extends TestCase
{
    public function testExceptionMessagesAndNestedRequestValuesCannotLeakPrivateData(): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'request', Level::Error, 'Uncaught exception containing private@example.org', [
            'exception' => new \RuntimeException('Private body: confidential text'),
            'parameters' => ['email' => 'private@example.org'],
            'nested' => ['body' => 'confidential text', 'access_token' => 'secret-token'],
        ]);
        $safe = (new LogContextProcessor(new RequestStack()))($record);
        $encoded = json_encode([$safe->message, $safe->context], JSON_THROW_ON_ERROR);
        foreach (['private@example.org', 'confidential text', 'secret-token'] as $private) {
            self::assertStringNotContainsString($private, $encoded);
        }
        self::assertStringContainsString('RuntimeException', $safe->message);
    }
}
