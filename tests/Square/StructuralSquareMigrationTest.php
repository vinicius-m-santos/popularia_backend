<?php

declare(strict_types=1);

namespace App\Tests\Square;

use App\Entity\ContentReport;
use App\Entity\Square;
use App\Entity\SquareMessage;
use App\Entity\SquareReaction;
use App\Entity\SquareVisit;
use App\Entity\Sticker;
use App\Entity\StickerPack;
use App\Tests\ApiTestCase;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20261027000000;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../migrations/Version20261027000000.php';

final class StructuralSquareMigrationTest extends ApiTestCase
{
    private function migration(): Version20261027000000
    {
        return new Version20261027000000($this->em()->getConnection(), new NullLogger());
    }

    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->em()->getConnection()->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $this->em()->clear();
    }

    public function testEmptyDatabaseIsSupported(): void
    {
        $migration = $this->migration();
        $migration->up(new Schema());
        $this->execute($migration);
        self::assertSame(0, $this->em()->getRepository(Square::class)->count([]));
    }

    public function testBackfillAndNormalizationPreserveExistingReferencesAndAreIdempotent(): void
    {
        $owner = $this->createUser('dona');
        $reporter = $this->createUser('leitora');
        $now = new \DateTimeImmutable('2026-10-26T18:00:00Z');
        $comunas = [];
        foreach (['active_missing', 'archived_missing', 'active_active', 'active_closed', 'archived_active', 'archived_closed', 'founder_missing'] as $label) {
            $comuna = $this->createComuna(str_replace('_', '-', $label), owner: in_array($label, ['active_missing', 'founder_missing'], true) ? $owner : null);
            if (str_starts_with($label, 'archived')) {
                $comuna->archive($now);
                $this->em()->flush();
            }
            $comunas[$label] = $comuna;
        }
        $kept = $this->em()->getRepository(Square::class)->findOneBy(['comuna' => $comunas['active_active']]);
        self::assertNotNull($kept);
        $pack = $this->em()->getRepository(StickerPack::class)->findOneBy(['comuna' => $kept->comuna]);
        self::assertNotNull($pack);
        $sticker = new Sticker($pack, 'FICA', '✊');
        $message = new SquareMessage($kept, $owner, 'Histórico preservado', null, null, $now, 1, 'migration_message_1');
        $reply = new SquareMessage($kept, $reporter, null, $sticker, $message, $now, 2, 'migration_message_2');
        $visit = new SquareVisit($kept, $owner);
        $visit->lastReadId = $message->id;
        $visit->seenAt = $now;
        $kept->version = 2;
        foreach ([$sticker, $message, $reply, new SquareReaction($message, $reporter, '✊'), $visit, new ContentReport($comunas['active_active'], $reporter, 'square_message', $message->id, 'Teste de preservação', $now)] as $entity) {
            $this->em()->persist($entity);
        }
        $independent = new Square(null, $owner, $now);
        $independent->close($now);
        $this->em()->persist($independent);
        $this->em()->flush();
        $connection = $this->em()->getConnection();
        $ids = [];
        foreach ($comunas as $label => $comuna) {
            $id = $comuna->getId()->toRfc4122();
            $ids[$label] = $connection->fetchOne('SELECT id FROM square WHERE comuna_id = ?', [$id]);
            if (str_ends_with($label, 'missing')) {
                $connection->executeStatement('DELETE FROM square WHERE comuna_id = ?', [$id]);
                $connection->executeStatement('DELETE FROM sticker_pack WHERE comuna_id = ?', [$id]);
            } elseif ('active_closed' === $label) {
                $connection->executeStatement("UPDATE square SET status = 'closed', closed_at = ? WHERE comuna_id = ?", [$now->format('c'), $id]);
            } elseif ('archived_active' === $label) {
                $connection->executeStatement("UPDATE square SET status = 'active', closed_at = NULL WHERE comuna_id = ?", [$id]);
            }
        }
        // Legado com founder, mas sem owner: usar a autoria conhecida sem criar conta fictícia.
        $connection->executeStatement('UPDATE comuna SET owner_id = NULL WHERE id = ?', [$comunas['founder_missing']->getId()->toRfc4122()]);
        $tables = ['square_message', 'square_reaction', 'square_visit', 'sticker', 'sticker_pack', 'content_report'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }
        $migration = $this->migration();
        $migration->up(new Schema());
        $this->execute($migration);
        self::assertSame(count($comunas), (int) $connection->fetchOne('SELECT COUNT(*) FROM square WHERE comuna_id IS NOT NULL'));
        foreach ($comunas as $label => $comuna) {
            $row = $connection->fetchAssociative('SELECT * FROM square WHERE comuna_id = ?', [$comuna->getId()->toRfc4122()]);
            self::assertNotFalse($row);
            self::assertSame(str_starts_with($label, 'archived') ? 'closed' : 'active', $row['status']);
            if (str_starts_with($label, 'archived')) {
                self::assertEquals($now, new \DateTimeImmutable((string) $row['closed_at']));
            } else {
                self::assertNull($row['closed_at']);
            }
            if (!str_ends_with($label, 'missing')) {
                self::assertSame($ids[$label], $row['id']);
            }
            if (in_array($label, ['active_missing', 'founder_missing'], true)) {
                self::assertSame($owner->getId()->toRfc4122(), $row['created_by_id']);
                self::assertEquals($comuna->getCreatedAt()->format('Y-m-d H:i:s'), (new \DateTimeImmutable((string) $row['created_at']))->format('Y-m-d H:i:s'));
            }
        }
        foreach ($tables as $table) {
            $after = $connection->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
            if ('sticker_pack' === $table) {
                $after = array_values(array_filter($after, static fn (array $p) => in_array($p['id'], array_column($before[$table], 'id'), true)));
            }
            self::assertSame($before[$table], $after, $table);
        }
        self::assertSame('closed', $connection->fetchOne('SELECT status FROM square WHERE id = ?', [$independent->id->toRfc4122()]));
        $all = $connection->fetchAllAssociative('SELECT * FROM square ORDER BY id');
        $again = $this->migration();
        $again->up(new Schema());
        $this->execute($again);
        self::assertSame($all, $connection->fetchAllAssociative('SELECT * FROM square ORDER BY id'));
    }

    public function testRollbackDoesNotDeleteSquares(): void
    {
        $this->createComuna('permanece', owner: $this->createUser('dona'));
        $connection = $this->em()->getConnection();
        $before = $connection->fetchAllAssociative('SELECT * FROM square ORDER BY id');
        $migration = $this->migration();
        $migration->down(new Schema());
        $this->execute($migration);
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM square ORDER BY id'));
    }

    public function testRollbackRefusesToInventUnknownAuthors(): void
    {
        $this->createComuna('equipe');
        $this->expectException(AbortMigration::class);
        $this->migration()->down(new Schema());
    }
}
