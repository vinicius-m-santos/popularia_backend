<?php

declare(strict_types=1);

namespace App\Command;

use App\Auth\AdminAudit;
use App\Enum\PlatformRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Único caminho para dar ou tirar papéis da plataforma. A API nunca aceita "role" do cliente.
 *   php bin/console app:user:set-role camilarocha admin "Equipe de operação, chamado #12".
 */
#[AsCommand(name: 'app:user:set-role', description: 'Define o papel de uma conta na plataforma (user, platform_moderator, admin).')]
final class SetUserRoleCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AdminAudit $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Handle da conta (sem @).')] string $handle,
        #[Argument('user, platform_moderator ou admin.')] string $role,
        #[Argument('Motivo, para a auditoria.')] string $reason,
    ): int {
        $user = $this->users->findOneByHandle($handle);
        $newRole = PlatformRole::tryFrom($role);
        if (null === $user || null === $newRole || mb_strlen(trim($reason)) < 5) {
            $io->error('Informe um handle existente, um papel válido e um motivo.');

            return Command::INVALID;
        }

        $previous = $user->getPlatformRole();
        $user->changePlatformRole($newRole, $this->clock->now());
        $this->em->flush();
        $this->audit->record(null, 'user.role_changed', $user->getId(), trim($reason), ['from' => $previous->value, 'to' => $newRole->value]);

        $io->success(\sprintf('@%s agora tem o papel "%s".', $user->getHandle(), $newRole->value));

        return Command::SUCCESS;
    }
}
