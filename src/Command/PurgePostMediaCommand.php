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

#[AsCommand(name: 'app:post-media:purge', description: 'Remove mídias órfãs, falhas ou marcadas para limpeza.')]
final class PurgePostMediaCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly ObjectStorage $storage, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = $this->clock->now()->modify('-24 hours');
        $rows = $this->db->fetchAllAssociative("SELECT m.id, m.storage_key FROM post_media m WHERE (m.status IN ('failed', 'purge_pending') OR (m.status = 'ready' AND m.created_at < ?)) AND NOT EXISTS (SELECT 1 FROM post p WHERE p.media_id = m.id) ORDER BY m.created_at LIMIT 100", [$before], [\Doctrine\DBAL\Types\Types::DATETIMETZ_IMMUTABLE]);
        foreach ($rows as $row) {
            $this->storage->delete($row['storage_key']);
            $this->db->executeStatement("UPDATE post_media SET status = 'purged' WHERE id = ? AND NOT EXISTS (SELECT 1 FROM post WHERE media_id = ?)", [$row['id'], $row['id']]);
        }
        $output->writeln(sprintf('%d mídia(s) removida(s).', count($rows)));

        return Command::SUCCESS;
    }
}
