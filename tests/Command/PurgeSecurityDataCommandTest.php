<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\EvidenceHold;
use App\Entity\SecurityEvent;
use App\Enum\PlatformRole;
use App\Enum\SecurityEventType;
use App\Tests\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeSecurityDataCommandTest extends ApiTestCase
{
    public function testOldEventsArePurgedExceptHeldOnes(): void
    {
        $investigated = $this->createUser('investigada');
        $other = $this->createUser('outra');
        $admin = $this->createUser('admin.ops', role: PlatformRole::Admin);
        $old = new \DateTimeImmutable('-200 days');
        $recent = new \DateTimeImmutable('-1 day');
        foreach ([[$investigated, $old], [$other, $old], [$other, $recent], [null, $old]] as [$user, $when]) {
            $this->em()->persist(new SecurityEvent(SecurityEventType::LoginFailed, $user, null, '203.0.113.7', [], $when));
        }
        $this->em()->persist(new EvidenceHold($investigated, 'Investigação de invasão, chamado 9', $admin, new \DateTimeImmutable()));
        $this->em()->flush();

        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:security:purge'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->em()->clear();
        $remaining = $this->em()->getRepository(SecurityEvent::class)->findAll();
        self::assertCount(2, $remaining);
        $owners = array_map(static fn (SecurityEvent $e) => $e->getUser()?->getHandle(), $remaining);
        self::assertEqualsCanonicalizing(['investigada', 'outra'], $owners);
    }
}
