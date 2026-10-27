<?php

declare(strict_types=1);

namespace App\Tests\Square;

use App\Entity\Square;
use App\Entity\StickerPack;
use App\Tests\ApiTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class StructuralSquareTest extends ApiTestCase
{
    public function testFixturePersistenceCreatesSquareAndPackForPlatformComuna(): void
    {
        $comuna = $this->createComuna('plataforma');
        $square = $this->em()->getRepository(Square::class)->findOneBy(['comuna' => $comuna]);
        self::assertNotNull($square);
        self::assertNull($square->createdBy);
        self::assertSame('active', $square->status);
        self::assertNotNull($this->em()->getRepository(StickerPack::class)->findOneBy(['comuna' => $comuna]));
        $comuna->archive(new \DateTimeImmutable('2026-10-26T18:00:00Z'));
        $this->em()->flush();
        self::assertSame('closed', $square->status);
        self::assertEquals($comuna->getArchivedAt(), $square->closedAt);
    }

    public function testSquareFailureRollsBackComunaCreation(): void
    {
        $this->createUser('criadora');
        $token = $this->login('criadora@exemplo.org')['accessToken'];
        $connection = $this->em()->getConnection();
        $connection->executeStatement('ALTER TABLE square ADD CONSTRAINT test_square_failure CHECK (comuna_id IS NULL) NOT VALID');
        $this->request('POST', '/comunas', ['name' => 'Rollback da Praça', 'description' => 'Não pode ficar pela metade.', 'visibility' => 'public', 'topics' => ['comun'], 'rules' => []], $token);
        $this->assertStatus(500);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM comuna'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM square'));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM sticker_pack WHERE scope = 'comuna'"));
    }

    public function testSquareFailureRollsBackComunaArchival(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('inteira', owner: $owner);
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $connection = $this->em()->getConnection();
        $connection->executeStatement("ALTER TABLE square ADD CONSTRAINT test_square_archive_failure CHECK (status = 'active') NOT VALID");
        $this->request('POST', '/comunas/inteira/archive', token: $token);
        $this->assertStatus(500);
        self::assertSame('active', $connection->fetchOne('SELECT status FROM comuna WHERE id = ?', [$comuna->getId()->toRfc4122()]));
        self::assertSame('active', $connection->fetchOne('SELECT status FROM square WHERE comuna_id = ?', [$comuna->getId()->toRfc4122()]));
        self::assertNull($connection->fetchOne('SELECT closed_at FROM square WHERE comuna_id = ?', [$comuna->getId()->toRfc4122()]));
    }

    public function testUniqueIndexRefusesSecondSquare(): void
    {
        $comuna = $this->createComuna('unica');
        $connection = $this->em()->getConnection();
        try {
            $connection->transactional(static function () use ($connection, $comuna): void {
                $connection->executeStatement("INSERT INTO square (id, comuna_id, created_by_id, status, version, created_at, updated_at) VALUES (uuidv7(), ?, NULL, 'active', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)", [$comuna->getId()->toRfc4122()]);
            });
            self::fail('A constraint precisa impedir uma segunda Praça.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM square WHERE comuna_id = ?', [$comuna->getId()->toRfc4122()]));
        }
    }

    public function testStructuralSquareCannotHaveIndependentLifecycle(): void
    {
        $comuna = $this->createComuna('estrutural');
        $square = $this->em()->getRepository(Square::class)->findOneBy(['comuna' => $comuna]);
        self::assertNotNull($square);
        $this->expectException(\LogicException::class);
        $square->close(new \DateTimeImmutable());
    }

    public function testDomainPreservesLifecycleForFutureIndependentSquares(): void
    {
        $now = new \DateTimeImmutable();
        $square = new Square(null, null, $now);
        $square->close($now);
        self::assertSame('closed', $square->status);
        self::assertSame($now, $square->closedAt);
        self::assertFalse($square->synchronizeWithComuna($now));
        $square->reopen($now->modify('+1 hour'));
        self::assertSame('active', $square->status);
        self::assertNull($square->closedAt);
    }
}
