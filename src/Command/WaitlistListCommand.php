<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\WaitlistEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exporta a lista de espera do site. Dados pessoais: rode apenas em ambiente
 * autorizado; o CSV vai para stdout, sem endpoint HTTP público.
 */
#[AsCommand(name: 'app:waitlist:list', description: 'Lista os e-mails da lista de espera (tabela waitlist_entry).')]
final readonly class WaitlistListCommand
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Saída CSV em vez de tabela; use > arquivo.csv para exportar.')] bool $csv = false,
        #[Option('Limita a quantidade de linhas; 0 lista todas.')] int $limit = 0,
    ): int {
        if ($limit < 0) {
            $io->error('O limite não pode ser negativo.');

            return Command::INVALID;
        }

        /** @var list<WaitlistEntry> $entries */
        $entries = $this->em->getRepository(WaitlistEntry::class)->findBy([], ['createdAt' => 'DESC'], $limit > 0 ? $limit : null);

        if ($csv) {
            $io->writeln(self::csv(['email', 'created_at', 'source']));
            foreach ($entries as $entry) {
                $io->writeln(self::csv([
                    $entry->getEmail(),
                    $entry->getCreatedAt()->format(\DATE_ATOM),
                    $entry->getSource() ?? '',
                ]));
            }

            return Command::SUCCESS;
        }

        $io->title('Lista de espera');
        if ([] === $entries) {
            $io->warning('Nenhum cadastro encontrado.');

            return Command::SUCCESS;
        }
        $io->text(\sprintf('%d cadastro(s), do mais recente para o mais antigo.', \count($entries)));
        $io->table(
            ['E-mail', 'Data', 'Origem'],
            array_map(static fn (WaitlistEntry $entry): array => [
                $entry->getEmail(),
                $entry->getCreatedAt()->format('Y-m-d H:i T'),
                $entry->getSource() ?? '—',
            ], $entries),
        );

        return Command::SUCCESS;
    }

    /** @param list<string> $fields */
    private static function csv(array $fields): string
    {
        return implode(',', array_map(static fn (string $field): string => '"'.str_replace('"', '""', $field).'"', $fields));
    }
}
