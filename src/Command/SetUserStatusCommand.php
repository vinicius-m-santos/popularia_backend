<?php

declare(strict_types=1);

namespace App\Command;

use App\Auth\AdminAudit;
use App\Auth\SecurityEventRecorder;
use App\Auth\SessionManager;
use App\Enum\AccountStatus;
use App\Enum\SecurityEventType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Suspende ou reativa uma conta. Suspender encerra todas as sessões; a partir daí a conta
 * não entra nem usa tokens antigos. Ferramenta provisória até existir o fluxo de moderação
 * com decisão registrada e recurso (docs/moderacao.md).
 */
#[AsCommand(name: 'app:user:set-status', description: 'Suspende (suspended) ou reativa (active) uma conta.')]
final class SetUserStatusCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly SessionManager $sessions,
        private readonly SecurityEventRecorder $events,
        private readonly AdminAudit $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Handle da conta (sem @).')] string $handle,
        #[Argument('active ou suspended.')] string $status,
        #[Argument('Motivo, para a auditoria.')] string $reason,
    ): int {
        $user = $this->users->findOneByHandle($handle);
        $newStatus = AccountStatus::tryFrom($status);
        if (null === $user || null === $newStatus || mb_strlen(trim($reason)) < 5) {
            $io->error('Informe um handle existente, um estado válido e um motivo.');

            return Command::INVALID;
        }

        $previous = $user->getStatus();
        $user->changeStatus($newStatus, $this->clock->now());
        $this->em->flush();
        if (AccountStatus::Suspended === $newStatus) {
            $this->sessions->revokeAll($user, 'account_suspended');
        }
        $this->events->record(SecurityEventType::AccountStatusChanged, $user, context: ['from' => $previous->value, 'to' => $newStatus->value]);
        $this->audit->record(null, 'user.status_changed', $user->getId(), trim($reason), ['from' => $previous->value, 'to' => $newStatus->value]);

        $io->success(\sprintf('@%s agora está "%s".', $user->getHandle(), $newStatus->value));

        return Command::SUCCESS;
    }
}
