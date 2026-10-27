<?php

declare(strict_types=1);

namespace App\Command;

use App\Account\AccountDeletionService;
use App\Auth\AdminAudit;
use App\Enum\AccountStatus;
use App\Repository\UserAuthIdentityRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Pedido pelo suporte exige verificação humana fora do CLI; não existe endpoint/bypass público. */
#[AsCommand(name: 'app:account:delete', description: 'Executa pedido de exclusão recebido pelo suporte, após confirmar identidade e autorização Apple quando aplicável.')]
final readonly class DeleteAccountCommand
{
    public function __construct(private UserRepository $users, private UserAuthIdentityRepository $identities, private AccountDeletionService $deletion, private AdminAudit $audit)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Handle atual da conta.')] string $handle,
        #[Argument('Protocolo e justificativa da verificação, sem credenciais nem cópia de documentos.')] string $reason,
        #[Option('Declara que a identidade foi confirmada e o pedido é autêntico.')] bool $identityVerified = false,
        #[Option('Declara que acesso Apple foi revogado manualmente e confirmado no atendimento (TN3194).')] bool $appleAccessRevoked = false,
    ): int {
        $user = $this->users->findOneByHandle($handle);
        if (null === $user || AccountStatus::Deleted === $user->getStatus() || !$identityVerified || mb_strlen(trim($reason)) < 10) {
            $io->error('Confirme identidade, conta existente e justificativa do atendimento.');

            return Command::INVALID;
        }
        if ($this->identities->hasProvider($user, 'apple') && !$appleAccessRevoked) {
            $io->error('Confirme a revogação Apple no atendimento antes de executar. A alternativa no app obtém código novo e revoga automaticamente.');

            return Command::INVALID;
        }
        if (!$io->confirm('Apagar definitivamente a conta e os dados pessoais conforme a política de exclusão?', false)) {
            return Command::FAILURE;
        }
        $id = $user->getId();
        $this->deletion->delete($user);
        $this->audit->record(null, 'account.deleted_by_support', $id, trim($reason), ['identity_verified' => true, 'apple_access_revoked' => $appleAccessRevoked]);
        $io->success('Conta excluída. Acompanhe cleanup de storage e solicitações nos fornecedores.');

        return Command::SUCCESS;
    }
}
