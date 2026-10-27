<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AdminAuditLog;
use App\Entity\Comuna;
use App\Entity\ComunaMembership;
use App\Entity\Square;
use App\Entity\StickerPack;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\ComunaRole;
use App\Tests\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Uid\Uuid;

final class ComunaCreationTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private static function payload(string $name = 'Hortas do Bairro'): array
    {
        return [
            'name' => $name,
            'description' => 'Mutirões e trocas de mudas.',
            'visibility' => 'private',
            'topics' => ['clima', 'comun'],
            'rules' => [['title' => 'Respeito sempre', 'body' => 'Sem ataques pessoais.']],
        ];
    }

    public function testVerifiedUserCreatesComunaAndBecomesOwner(): void
    {
        $camila = $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $comuna = $this->request('POST', '/comunas', self::payload('  Hortas   do Bairro  '), $token);

        $this->assertStatus(201);
        self::assertSame('hortas-do-bairro', $comuna['slug']);
        self::assertSame('Hortas do Bairro', $comuna['name']);
        self::assertSame('private', $comuna['visibility']);
        self::assertSame('active', $comuna['status']);
        self::assertSame(1, $comuna['memberCount']);
        self::assertSame('camila', $comuna['owner']['handle']);
        self::assertSame('founder', $comuna['viewer']['role']);
        self::assertTrue($comuna['viewer']['permissions']['canEditDetails']);
        self::assertSame([['title' => 'Respeito sempre', 'body' => 'Sem ataques pessoais.']], $comuna['rules']);

        $saved = $this->fresh(Comuna::class, $comuna['id']);
        self::assertTrue($saved->isOwnedBy($camila));
        $square = $this->em()->getRepository(Square::class)->findOneBy(['comuna' => $saved]);
        self::assertNotNull($square);
        self::assertSame('active', $square->status);
        self::assertNull($square->closedAt);
        self::assertNotNull($square->createdBy);
        self::assertTrue($camila->getId()->equals($square->createdBy->getId()));
        self::assertNotNull($this->em()->getRepository(StickerPack::class)->findOneBy(['comuna' => $saved]));

        $membership = $this->em()->getRepository(ComunaMembership::class)->findOneBy(['comuna' => $saved]);
        self::assertSame(ComunaRole::Founder, $membership?->getRole());
        self::assertNotNull($this->fresh(User::class, $camila->getId())->getLastComunaCreatedAt());
        $audit = $this->em()->getRepository(AdminAuditLog::class)->findOneBy(['action' => 'comuna.created']);
        self::assertSame($comuna['id'], $audit?->getDetails()['comuna_id']);
        $immediate = $this->request('GET', '/comunas/'.$comuna['slug'].'/square', token: $token);
        $this->assertStatus(200);
        self::assertSame($square->id->toRfc4122(), $immediate['id']);
    }

    public function testClientCannotChooseOwnerSlugStatusOrCounters(): void
    {
        $other = $this->createUser('outra');
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        foreach ([['owner' => $other->getId()->toRfc4122()], ['slug' => 'oficial'], ['status' => 'archived'], ['memberCount' => 900], ['id' => Uuid::v7()->toRfc4122()]] as $extra) {
            $this->request('POST', '/comunas', self::payload() + $extra, $token);
            $this->assertStatus(400);
        }
        self::assertSame(0, $this->em()->getRepository(Comuna::class)->count([]));
    }

    public function testUnverifiedEmailCannotCreate(): void
    {
        $this->createUser('camila', verified: false);

        $body = $this->request('POST', '/comunas', self::payload(), $this->login('camila@exemplo.org')['accessToken']);

        $this->assertStatus(403);
        self::assertSame('/errors/email-not-verified', $body['type']);
    }

    public function testSuspendedAccountCannotCreate(): void
    {
        $camila = $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];
        $camila->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();

        $body = $this->request('POST', '/comunas', self::payload(), $token);

        $this->assertStatus(403);
        self::assertSame('/errors/account-inactive', $body['type']);
        self::assertSame(0, $this->em()->getRepository(Comuna::class)->count([]));
    }

    public function testFourthActiveComunaIsRefused(): void
    {
        $camila = $this->createUser('camila');
        foreach (['uma', 'duas', 'tres'] as $slug) {
            $this->createComuna($slug, owner: $camila);
        }

        $body = $this->request('POST', '/comunas', self::payload(), $this->login('camila@exemplo.org')['accessToken']);

        $this->assertStatus(409);
        self::assertSame('/errors/comuna-limit-reached', $body['type']);
        self::assertSame(3, $this->em()->getRepository(Comuna::class)->count([]));
    }

    public function testArchivedComunaDoesNotTakeAnActiveSlot(): void
    {
        $camila = $this->createUser('camila');
        foreach (['uma', 'duas', 'tres'] as $slug) {
            $this->createComuna($slug, owner: $camila);
        }
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $quota = $this->request('GET', '/me/comuna-quota', token: $token);
        self::assertSame(['activeOwned' => 3, 'limit' => 3, 'canCreate' => false, 'blockedBy' => '/errors/comuna-limit-reached'], array_intersect_key($quota, array_flip(['activeOwned', 'limit', 'canCreate', 'blockedBy'])));

        $this->request('POST', '/comunas/uma/archive', token: $token);
        $this->assertStatus(200);
        $this->request('POST', '/comunas', self::payload(), $token);

        $this->assertStatus(201);
    }

    public function testConsecutiveCreationsOnlyRespectTheActiveLimit(): void
    {
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];
        foreach (['Primeira', 'Segunda', 'Terceira'] as $name) {
            $this->request('POST', '/comunas', self::payload($name), $token);
            $this->assertStatus(201);
        }

        $body = $this->request('POST', '/comunas', self::payload('Quarta'), $token);
        $this->assertStatus(409);
        self::assertSame('/errors/comuna-limit-reached', $body['type']);
        $quota = $this->request('GET', '/me/comuna-quota', token: $token);
        self::assertFalse($quota['canCreate']);
        self::assertSame(3, $quota['activeOwned']);
        self::assertSame(0, $quota['intervalDays']);
        self::assertNull($quota['nextCreationAt']);
    }

    public function testDeletingOrArchivingImmediatelyReleasesASlot(): void
    {
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        foreach (['Primeira', 'Segunda', 'Terceira'] as $name) {
            $this->request('POST', '/comunas', self::payload($name), $token);
            $this->assertStatus(201);
        }
        $this->request('DELETE', '/comunas/primeira', token: $token);
        $this->assertStatus(204);
        $quota = $this->request('GET', '/me/comuna-quota', token: $token);
        self::assertTrue($quota['canCreate']);
        self::assertSame(2, $quota['activeOwned']);
        $this->request('POST', '/comunas', self::payload('Primeira'), $token);
        $this->assertStatus(201);

        $this->request('POST', '/comunas/segunda/archive', token: $token);
        $this->assertStatus(200);
        $quota = $this->request('GET', '/me/comuna-quota', token: $token);
        self::assertTrue($quota['canCreate']);
        self::assertSame(2, $quota['activeOwned']);
        $this->request('POST', '/comunas', self::payload('Quarta'), $token);
        $this->assertStatus(201);
    }

    public function testDuplicateNamesAreRefusedBySlug(): void
    {
        $this->createComuna('tarifa-zero');
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        foreach (['Tarifa Zero', 'TARIFA   zero!', 'Tarifa-Zéro'] as $name) {
            $body = $this->request('POST', '/comunas', self::payload($name), $token);
            $this->assertStatus(422);
            self::assertSame('name', $body['violations'][0]['propertyPath']);
        }

        $check = $this->request('GET', '/comuna-names/availability?name='.rawurlencode('Tarifa Zero'), token: $token);
        self::assertSame(['name' => 'Tarifa Zero', 'slug' => 'tarifa-zero', 'available' => false], array_intersect_key($check, array_flip(['name', 'slug', 'available'])));
        $free = $this->request('GET', '/comuna-names/availability?name='.rawurlencode('Tarifa Zero BH'), token: $token);
        self::assertTrue($free['available']);
        self::assertSame('tarifa-zero-bh', $free['slug']);
    }

    public function testNamesThatDoNotMakeAnAddressAreRefused(): void
    {
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        foreach (['!!! ??', 'Admin', 'ab'] as $name) {
            $this->request('POST', '/comunas', self::payload($name), $token);
            $this->assertStatus(422);
        }
        self::assertSame(0, $this->em()->getRepository(Comuna::class)->count([]));
    }

    /**
     * Duas transações simultâneas da mesma pessoa, em conexões de verdade (fora da transação
     * do teste): o gatilho do banco trava a conta antes de contar, então a segunda espera a
     * primeira terminar e, depois disso, já enxerga a 3ª comuna e recusa a 4ª.
     */
    public function testConcurrentCreationsCannotExceedTheLimit(): void
    {
        $params = $this->em()->getConnection()->getParams();
        $setup = DriverManager::getConnection($params);
        $first = DriverManager::getConnection($params);
        $second = DriverManager::getConnection($params);
        $ownerId = Uuid::v7()->toRfc4122();

        try {
            $setup->insert('app_user', [
                'id' => $ownerId, 'email' => $ownerId.'@exemplo.org', 'password' => 'x', 'handle' => 'c'.substr(md5($ownerId), 0, 12),
                'display_name' => 'Concorrência', 'created_at' => '2026-10-26 12:00:00+00', 'updated_at' => '2026-10-26 12:00:00+00',
            ]);
            self::insertComuna($setup, $ownerId);
            self::insertComuna($setup, $ownerId);

            $first->beginTransaction();
            self::insertComuna($first, $ownerId);

            // A segunda transação esbarra na trava da conta enquanto a primeira não termina.
            $second->executeStatement("SET lock_timeout = '300ms'");
            $second->beginTransaction();
            try {
                self::insertComuna($second, $ownerId);
                self::fail('A segunda criação não deveria passar enquanto a primeira está em andamento.');
            } catch (\Doctrine\DBAL\Exception $e) {
                self::assertStringContainsString('lock timeout', $e->getMessage());
            } finally {
                $second->rollBack();
            }

            $first->commit();

            $second->beginTransaction();
            try {
                self::insertComuna($second, $ownerId);
                self::fail('A 4ª comuna ativa deveria ser recusada pelo banco.');
            } catch (\Doctrine\DBAL\Exception $e) {
                self::assertStringContainsString('comuna_owner_limit_reached', $e->getMessage());
            } finally {
                $second->rollBack();
            }

            self::assertSame(3, (int) $setup->fetchOne('SELECT count(*) FROM comuna WHERE owner_id = ?', [$ownerId]));
        } finally {
            if ($first->isTransactionActive()) {
                $first->rollBack();
            }
            $setup->executeStatement('DELETE FROM comuna WHERE owner_id = ?', [$ownerId]);
            $setup->executeStatement('DELETE FROM app_user WHERE id = ?', [$ownerId]);
            $setup->close();
            $first->close();
            $second->close();
        }
    }

    private static function insertComuna(Connection $connection, string $ownerId): void
    {
        $id = Uuid::v7()->toRfc4122();
        $connection->insert('comuna', [
            'id' => $id, 'slug' => 'c-'.substr(md5($id), 0, 20), 'name' => 'Concorrente', 'description' => 'x', 'place' => '',
            'visibility' => 'public', 'tone' => 'red', 'founded_year' => 2026, 'created_at' => '2026-10-26 12:00:00+00', 'owner_id' => $ownerId,
        ]);
    }
}
