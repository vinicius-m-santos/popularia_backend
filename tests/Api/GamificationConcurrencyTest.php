<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/** Duas instâncias reais do serviço, em conexões PostgreSQL simultaneamente bloqueadas. */
final class GamificationConcurrencyTest extends ApiTestCase
{
    public function testSameSourceAwardsXpExactlyOnceUnderContention(): void
    {
        $params = $this->em()->getConnection()->getParams();
        $pdo = new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $params['host'], $params['port'], $params['dbname']), $params['user'], $params['password']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $id = Uuid::v7()->toRfc4122();
        $source = Uuid::v7()->toRfc4122();
        $handle = 'race'.bin2hex(random_bytes(5));
        $processes = [];
        try {
            $pdo->prepare('INSERT INTO app_user (id, email, password, handle, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())')
                ->execute([$id, $handle.'@example.test', 'x', $handle, 'Race test']);
            $pdo->prepare('INSERT INTO gamification_profile (user_id, xp_total, streak_current, updated_at) VALUES (?, 0, 0, NOW())')->execute([$id]);
            $pdo->beginTransaction();
            $pdo->prepare('SELECT user_id FROM gamification_profile WHERE user_id = ? FOR UPDATE')->execute([$id]);
            $script = <<<'PHP'
                require 'vendor/autoload.php';
                (new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
                $kernel = new App\Kernel('test', true);
                $kernel->boot();
                $container = $kernel->getContainer()->get('test.service_container');
                $em = $container->get(Doctrine\ORM\EntityManagerInterface::class);
                $db = $em->getConnection();
                $db->executeStatement("SET statement_timeout = '10s'");
                $db->executeQuery("SELECT set_config('application_name', ?, false)", [getenv('RACE_LABEL')]);
                $user = $em->find(App\Entity\User::class, Symfony\Component\Uid\Uuid::fromString(getenv('RACE_USER')));
                $container->get(App\Gamification\GamificationService::class)->record(
                    $user, App\Gamification\GamificationAction::ComunaJoined,
                    Symfony\Component\Uid\Uuid::fromString(getenv('RACE_SOURCE')), new DateTimeImmutable('2026-10-30T15:00:00Z')
                );
                $kernel->shutdown();
                PHP;
            for ($i = 0; $i < 2; ++$i) {
                $process = new Process([PHP_BINARY, '-r', $script], dirname(__DIR__, 2), [
                    'APP_ENV' => 'test', 'RACE_USER' => $id, 'RACE_SOURCE' => $source, 'RACE_LABEL' => $handle,
                ]);
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            $waiting = $pdo->prepare("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
            $deadline = microtime(true) + 8;
            do {
                // As estatísticas não devem permanecer no snapshot da transação controladora.
                $pdo->query('SELECT pg_stat_clear_snapshot()');
                $waiting->execute([$handle]);
                $count = (int) $waiting->fetchColumn();
                if (2 === $count) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $count, 'As duas conexões devem disputar o mesmo lock antes de liberá-lo.');
            $pdo->commit();
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $entries = $pdo->prepare("SELECT COUNT(*) FROM gamification_entry WHERE user_id = ? AND action = 'comuna_joined'");
            $entries->execute([$id]);
            self::assertSame(1, (int) $entries->fetchColumn());
            $totals = $pdo->prepare('SELECT p.xp_total, p.streak_current, (SELECT SUM(e.xp) FROM gamification_entry e WHERE e.user_id = p.user_id) AS awarded FROM gamification_profile p WHERE user_id = ?');
            $totals->execute([$id]);
            $row = $totals->fetch(\PDO::FETCH_ASSOC);
            self::assertIsArray($row);
            self::assertGreaterThan(0, (int) $row['xp_total']);
            self::assertSame((int) $row['awarded'], (int) $row['xp_total']);
            self::assertSame(1, (int) $row['streak_current']);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            $pdo->prepare('DELETE FROM app_user WHERE id = ?')->execute([$id]);
        }
    }
}
