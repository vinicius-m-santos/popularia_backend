<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\PushWorker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:notifications:deliver', description: 'Entrega um lote de pushes e consulta recibos pendentes (agendar a cada minuto).')]
final readonly class DeliverPushCommand
{
    public function __construct(private PushWorker $worker, private LoggerInterface $logger)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Tamanho do lote, de 1 a 100.')] int $limit = 100, #[Option('Processa continuamente até o serviço encerrar.')] bool $watch = false, #[Option('Intervalo entre lotes no modo contínuo (1 a 60 segundos).')] int $interval = 5): int
    {
        try {
            do {
                $result = $this->worker->run($limit);
                if (!$watch || array_sum($result) > 0) {
                    $io->success(sprintf('Notificações processadas: %d; envios tentados: %d; recibos consultados: %d.', $result['queued'], $result['sent'], $result['receipts']));
                }
                if ($watch) {
                    sleep(max(1, min(60, $interval)));
                }
            } while ($watch);

            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $this->logger->error('Push worker failed.', ['exception' => $error]);
            $io->error('Falha no worker de push. Consulte os logs.');

            return Command::FAILURE;
        }
    }
}
