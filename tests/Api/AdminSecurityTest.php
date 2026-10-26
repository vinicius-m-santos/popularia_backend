<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AdminAuditLog;
use App\Enum\PlatformRole;
use App\Tests\ApiTestCase;
use Doctrine\DBAL\Exception\DriverException;

final class AdminSecurityTest extends ApiTestCase
{
    public function testSecurityEventsAreOnlyForAdmins(): void
    {
        $subject = $this->createUser('camila');
        $this->createUser('comum');
        $this->createUser('moderadora', role: PlatformRole::PlatformModerator);
        $uri = '/admin/security-events?userId='.$subject->getId()->toRfc4122().'&reason=investigacao+de+teste';

        $this->request('GET', $uri);
        $this->assertStatus(401);
        $this->request('GET', $uri, token: $this->login('comum@exemplo.org')['accessToken']);
        $this->assertStatus(403);
        $this->request('GET', $uri, token: $this->login('moderadora@exemplo.org')['accessToken']);
        $this->assertStatus(403);
        self::assertSame(0, $this->em()->getRepository(AdminAuditLog::class)->count([]));
    }

    public function testAdminQueryRequiresReasonAndIsAudited(): void
    {
        $subject = $this->createUser('camila');
        $admin = $this->createUser('admin.ops', role: PlatformRole::Admin);
        $this->login('camila@exemplo.org');
        $token = $this->login('admin.ops@exemplo.org')['accessToken'];

        $this->request('GET', '/admin/security-events?userId='.$subject->getId()->toRfc4122(), token: $token);
        $this->assertStatus(400);

        $events = $this->request('GET', '/admin/security-events?userId='.$subject->getId()->toRfc4122().'&reason=Chamado+123+invasao+suspeita', token: $token);

        $this->assertStatus(200);
        self::assertSame('login_succeeded', $events[0]['type']);
        self::assertNotEmpty($events[0]['ipAddress']);
        $audit = $this->em()->getRepository(AdminAuditLog::class)->findOneBy(['action' => 'security_events.viewed']);
        self::assertNotNull($audit);
        self::assertSame($admin->getId()->toRfc4122(), $audit->getActor()?->getId()->toRfc4122());
        self::assertSame($subject->getId()->toRfc4122(), $audit->getTargetUserId()?->toRfc4122());
        self::assertSame('Chamado 123 invasao suspeita', $audit->getReason());
    }

    public function testEvidenceHoldIsAudited(): void
    {
        $subject = $this->createUser('camila');
        $this->createUser('admin.ops', role: PlatformRole::Admin);
        $token = $this->login('admin.ops@exemplo.org')['accessToken'];

        $hold = $this->request('POST', '/admin/evidence-holds', ['userId' => $subject->getId()->toRfc4122(), 'reason' => 'Ameaça relatada, chamado 77'], $token);
        $this->assertStatus(201);
        self::assertTrue($hold['active']);

        $released = $this->request('POST', '/admin/evidence-holds/'.$hold['id'].'/release', ['reason' => 'Caso encerrado, chamado 77'], $token);
        $this->assertStatus(200);
        self::assertFalse($released['active']);

        $actions = array_map(static fn (AdminAuditLog $l) => $l->getAction(), $this->em()->getRepository(AdminAuditLog::class)->findBy([], ['occurredAt' => 'ASC']));
        self::assertSame(['evidence_hold.created', 'evidence_hold.released'], $actions);
    }

    public function testAuditLogCannotBeChanged(): void
    {
        $this->assertAuditStatementRefused("UPDATE admin_audit_log SET reason = 'apagado'");
    }

    public function testAuditLogCannotBeDeleted(): void
    {
        $this->assertAuditStatementRefused('DELETE FROM admin_audit_log');
    }

    private function assertAuditStatementRefused(string $sql): void
    {
        $admin = $this->createUser('admin.ops', role: PlatformRole::Admin);
        $this->em()->persist(new AdminAuditLog($admin, 'api', 'teste', null, 'motivo de teste', [], new \DateTimeImmutable()));
        $this->em()->flush();

        try {
            $this->em()->getConnection()->executeStatement($sql);
            self::fail('O banco deveria ter recusado: '.$sql);
        } catch (DriverException $e) {
            self::assertStringContainsString('apenas inserções', $e->getMessage());
        }
    }
}
