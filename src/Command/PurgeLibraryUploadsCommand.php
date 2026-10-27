<?php

declare(strict_types=1);

namespace App\Command;

use App\Library\ObjectStorage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:library:purge', description: 'Remove objetos de envios interrompidos há mais de 24 horas (até 100 por execução).')]
final class PurgeLibraryUploadsCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly ObjectStorage $storage, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = 0;
        for ($i = 0; $i < 100; ++$i) {
            $deleted = $this->connection->transactional(function (): bool {
                $file = $this->connection->executeQuery("SELECT f.id, f.storage_key FROM library_file f WHERE f.status IN ('pending','failed') AND f.created_at < :cutoff AND NOT EXISTS (SELECT 1 FROM library_document d WHERE d.file_id = f.id) ORDER BY f.id FOR UPDATE SKIP LOCKED LIMIT 1", ['cutoff' => $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:sP')])->fetchAssociative();
                if (false === $file) {
                    return false;
                }
                $this->storage->delete((string) $file['storage_key']);
                $this->connection->executeStatement("UPDATE library_file SET status = 'purged' WHERE id = ?", [$file['id']]);

                return true;
            });
            if (!$deleted) {
                break;
            }
            ++$removed;
        }
        $output->writeln(sprintf('%d objetos temporários removidos.', $removed));

        return Command::SUCCESS;
    }
}
