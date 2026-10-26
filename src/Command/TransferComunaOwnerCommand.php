<?php

declare(strict_types=1);

namespace App\Command;

use App\Api\ApiProblem;
use App\Content\ComunaGovernance;
use App\Repository\ComunaRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Transferência de propriedade feita pela equipe da plataforma, para quando o fluxo do app
 * (proposta + aceite) não serve: conta proprietária suspensa ou que pediu para sair. A nova
 * pessoa precisa participar da comuna e o limite de comunas ativas vale para ela. Auditado.
 */
#[AsCommand(name: 'app:comuna:transfer-owner', description: 'Passa a propriedade de uma comuna para um membro dela.')]
final class TransferComunaOwnerCommand
{
    public function __construct(
        private readonly ComunaRepository $comunas,
        private readonly UserRepository $users,
        private readonly ComunaGovernance $governance,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Endereço (slug) da comuna.')] string $slug,
        #[Argument('Handle (sem @) de quem passa a ser proprietário.')] string $handle,
        #[Argument('Motivo, para a auditoria.')] string $reason,
    ): int {
        $comuna = $this->comunas->findOneBySlug($slug);
        $user = $this->users->findOneByHandle($handle);
        if (null === $comuna || null === $user || mb_strlen(trim($reason)) < 5) {
            $io->error('Informe uma comuna existente, um handle existente e um motivo.');

            return Command::INVALID;
        }

        try {
            $this->governance->transferByPlatform($comuna, $user, trim($reason));
        } catch (ApiProblem $problem) {
            $io->error($problem->getTitle());

            return Command::FAILURE;
        }

        $io->success(\sprintf('c/%s agora pertence a @%s.', $comuna->getSlug(), $user->getHandle()));

        return Command::SUCCESS;
    }
}
