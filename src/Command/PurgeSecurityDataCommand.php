<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AccountTokenRepository;
use App\Repository\AuthSessionRepository;
use App\Repository\SecurityEventRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rotina de retenção. Rodar uma vez por dia (cron ou agendador da hospedagem):
 *   php bin/console app:security:purge
 *
 * - Registros de segurança (com IP) mais antigos que SECURITY_EVENT_RETENTION_DAYS,
 *   exceto os de contas com preservação de evidências ativa.
 * - Sessões encerradas ou sem uso há mais de 30 dias (e seus refresh tokens).
 * - Códigos de e-mail vencidos há mais de 7 dias.
 *
 * O prazo de produção precisa de validação jurídica (docs/seguranca.md, "Retenção").
 */
#[AsCommand(name: 'app:security:purge', description: 'Apaga registros de segurança, sessões e códigos fora do prazo de retenção.')]
final class PurgeSecurityDataCommand
{
    private const int STALE_SESSION_DAYS = 30;
    private const int EXPIRED_CODE_DAYS = 7;

    public function __construct(
        private readonly SecurityEventRepository $events,
        private readonly AuthSessionRepository $sessions,
        private readonly AccountTokenRepository $codes,
        private readonly ClockInterface $clock,
        private readonly int $securityEventRetentionDays,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Só mostra o que seria apagado.')] bool $dryRun = false): int
    {
        if ($this->securityEventRetentionDays < 1) {
            $io->error('SECURITY_EVENT_RETENTION_DAYS precisa ser pelo menos 1.');

            return Command::FAILURE;
        }

        $now = $this->clock->now();
        $eventsBefore = $now->modify(\sprintf('-%d days', $this->securityEventRetentionDays));
        if ($dryRun) {
            $io->note(\sprintf('Seriam apagados eventos de segurança anteriores a %s (UTC).', $eventsBefore->format(\DATE_ATOM)));

            return Command::SUCCESS;
        }

        $io->definitionList(
            ['Eventos de segurança' => $this->events->purgeOlderThan($eventsBefore)],
            ['Sessões' => $this->sessions->deleteStale($now->modify(\sprintf('-%d days', self::STALE_SESSION_DAYS)))],
            ['Códigos de e-mail' => $this->codes->deleteExpiredBefore($now->modify(\sprintf('-%d days', self::EXPIRED_CODE_DAYS)))],
        );

        return Command::SUCCESS;
    }
}
