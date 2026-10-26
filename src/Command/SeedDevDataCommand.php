<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Comuna;
use App\Entity\ComunaMembership;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Repository\ComunaRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Dados FICTÍCIOS para desenvolvimento: algumas comunas do protótipo do app, uma conta de
 * fundação e publicações de exemplo. Recusa rodar em produção. Pode rodar mais de uma vez.
 *   php bin/console app:dev:seed.
 *
 * A conta de fundação é proprietária de três comunas (o limite de comunas ativas por pessoa);
 * "hortas" fica como comuna da equipe (sem proprietário), com a mesma conta na moderação.
 */
#[AsCommand(name: 'app:dev:seed', description: 'Cria comunas e publicações fictícias para desenvolvimento.')]
final class SeedDevDataCommand
{
    private const array COMUNAS = [
        ['tarifa', 'Tarifa Zero BH', 'Luta pelo transporte público gratuito em Belo Horizonte e região metropolitana.', 'Belo Horizonte · MG', 'red', ['pol', 'comun', 'eco'], 2019, ComunaVisibility::Public, true],
        ['cursinho', 'Cursinho Popular Carolina', 'Cursinho pré-vestibular gratuito. Simulados, monitoria e grupos de estudo para o Enem.', 'São Paulo · SP', 'cream', ['edu', 'comun', 'livros'], 2021, ComunaVisibility::Public, true],
        ['hortas', 'Hortas da Zona Leste', 'Rede de hortas comunitárias da Zona Leste: mutirões, trocas de mudas e oficinas de compostagem.', 'São Paulo · SP', 'red', ['clima', 'comun', 'saude'], 2023, ComunaVisibility::Public, false],
        ['entregadores', 'Entregadores Unidos', 'Organização de entregadores e entregadoras por aplicativo. Espaço fechado para quem é da categoria.', 'Recife · PE', 'black', ['trab', 'sind'], 2022, ComunaVisibility::Private, true],
    ];

    private const array RULES = [
        ['Respeito sempre', 'Racismo, machismo, LGBTfobia e ataques pessoais levam a remoção e banimento.'],
        ['Fique no tema', 'Publicações precisam ter relação com o propósito da comuna.'],
        ['Informação com fonte', 'Dados e notícias precisam de link ou origem. Boatos são removidos.'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComunaRepository $comunas,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if ('prod' === $this->environment) {
            $io->error('Este comando cria dados fictícios e não roda em produção.');

            return Command::FAILURE;
        }

        $now = $this->clock->now();
        $founder = $this->users->findOneByHandle('equipe.demo');
        if (null === $founder) {
            $founder = new User('equipe.demo@popularia.local', 'equipe.demo', 'Equipe Demo (fictícia)', $now);
            $founder->setPasswordHash($this->passwordHasher->hashPassword($founder, 'demo-somente-local'), $now);
            $founder->markEmailVerified($now);
            $this->em->persist($founder);
        }

        foreach (self::COMUNAS as [$slug, $name, $description, $place, $tone, $topics, $year, $visibility, $owned]) {
            if (null !== $this->comunas->findOneBySlug($slug)) {
                continue;
            }
            $owner = $owned ? $founder : null;
            $comuna = new Comuna($slug, $name, $description, $place, $visibility, $tone, $topics, $year, $now, $owner);
            $comuna->setModerationNote('A moderação é escolhida em votação aberta a cada seis meses.');
            foreach (self::RULES as [$title, $body]) {
                $comuna->addRule($title, $body);
            }
            $this->em->persist($comuna);
            $this->em->persist(new ComunaMembership($comuna, $founder, null === $owner ? ComunaRole::Moderator : ComunaRole::Founder, $now));
            $this->em->persist(new Post($founder, $comuna, "Boas-vindas à {$name}! Leia as regras e se apresente nos comentários. (publicação fictícia)", $now));
            $this->em->flush();
            $this->comunas->adjustMemberCount($comuna, +1);
            $io->writeln("Comuna criada: {$slug}");
        }
        $this->em->flush();

        $io->success('Dados fictícios prontos. Conta de fundação: equipe.demo@popularia.local / demo-somente-local');

        return Command::SUCCESS;
    }
}
