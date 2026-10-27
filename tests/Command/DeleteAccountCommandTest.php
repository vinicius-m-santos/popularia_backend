<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Enum\AccountStatus;
use App\Tests\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DeleteAccountCommandTest extends ApiTestCase
{
    public function testSupportRequiresVerificationAndExplicitConfirmationAndAuditsDeletion(): void
    {
        $user = $this->createUser('solicitante');
        self::assertNotNull(self::$kernel);
        $command = new CommandTester((new Application(self::$kernel))->find('app:account:delete'));
        $arguments = ['handle' => 'solicitante', 'reason' => 'Protocolo teste: identidade confirmada'];
        self::assertSame(Command::INVALID, $command->execute($arguments));
        self::assertSame(AccountStatus::Active, $user->getStatus());
        $command->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $command->execute($arguments + ['--identity-verified' => true]));
        self::assertSame(AccountStatus::Deleted, $this->fresh(User::class, $user->getId())->getStatus());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'account.deleted_by_support'"));
    }
}
