<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\WaitlistEntry;
use App\Tests\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class WaitlistListCommandTest extends ApiTestCase
{
    public function testListsCsvAndTableNewestFirst(): void
    {
        $this->em()->persist(new WaitlistEntry('ana@example.com', new \DateTimeImmutable('-2 hours'), 'hero'));
        $this->em()->persist(new WaitlistEntry('bia@example.com', new \DateTimeImmutable('-1 hour')));
        $this->em()->flush();
        self::assertNotNull(self::$kernel);
        $command = (new Application(self::$kernel))->find('app:waitlist:list');

        $csv = new CommandTester($command);
        $csv->execute(['--csv' => true]);
        $csv->assertCommandIsSuccessful();
        $output = $csv->getDisplay();
        self::assertStringContainsString('"email","created_at","source"', $output);
        self::assertStringContainsString('"bia@example.com"', $output);
        self::assertStringContainsString('"ana@example.com"', $output);
        self::assertMatchesRegularExpression('/"bia@example\.com".*"ana@example\.com"/s', $output);

        $table = new CommandTester($command);
        $table->execute([]);
        $table->assertCommandIsSuccessful();
        self::assertStringContainsString('ana@example.com', $table->getDisplay());
        self::assertStringContainsString('bia@example.com', $table->getDisplay());
    }
}
