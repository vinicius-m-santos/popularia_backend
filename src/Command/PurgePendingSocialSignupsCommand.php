<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PendingSocialSignupRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:pending-social:cleanup', description: 'Apaga cadastros sociais pendentes expirados.')]
final readonly class PurgePendingSocialSignupsCommand
{
    public function __construct(private PendingSocialSignupRepository $pending, private ClockInterface $clock)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $deleted = $this->pending->deleteExpired($this->clock->now());
        $io->success(sprintf('%d cadastro(s) social(is) pendente(s) expirado(s) apagado(s).', $deleted));

        return Command::SUCCESS;
    }
}
