<?php

declare(strict_types=1);

namespace App\Command;

use App\Library\ObjectStorage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:library:purge', description: 'Remove PDFs sem uso: envios interrompidos, documentos excluídos e contas excluídas (até 100).')]
final class PurgeLibraryUploadsCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly ObjectStorage $storage, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Exibe o que seria removido sem apagar. Execute sempre antes de ativar cleanup automático em produção.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        if ($dryRun) {
            $output->writeln('<comment>[dry-run] Nenhum objeto será removido.</comment>');
        }
        $removed = 0;
        $after = '';
        for ($i = 0; $i < 100; ++$i) {
            $deleted = $this->connection->transactional(function () use ($dryRun, $output, &$after): bool {
                $file = $this->connection->executeQuery("SELECT f.id, f.storage_key FROM library_file f WHERE f.status <> 'purged' AND f.id::text > :after AND (
                    f.status = 'purge_pending'
                    OR (f.status IN ('pending','failed') AND f.created_at < :cutoff AND NOT EXISTS (SELECT 1 FROM library_document d WHERE d.file_id = f.id))
                    OR EXISTS (SELECT 1 FROM library_document d WHERE d.file_id = f.id AND d.status = 'deleted' AND d.removed_at < :cutoff)
                    ) AND NOT EXISTS (SELECT 1 FROM library_document d WHERE d.file_id = f.id AND d.status IN ('published', 'removed'))
                    ORDER BY f.id FOR UPDATE OF f SKIP LOCKED LIMIT 1", ['after' => $after, 'cutoff' => $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:sP')])->fetchAssociative();
                if (false === $file) {
                    return false;
                }
                $after = (string) $file['id'];
                if ($dryRun) {
                    $output->writeln(sprintf('[dry-run] Removeria: id=%s key=%s', $file['id'], $file['storage_key']));

                    return true;
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
        $label = $dryRun ? 'elegíveis para remoção' : 'objetos temporários removidos';
        $output->writeln(sprintf('%d %s.', $removed, $label));

        return Command::SUCCESS;
    }
}
