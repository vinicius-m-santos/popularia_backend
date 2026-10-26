<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Banco de testes recriado do zero a cada execução, só com as migrations: é a prova
// contínua de que elas se aplicam num banco limpo. Cada teste roda dentro de uma transação
// desfeita ao final (DAMA DoctrineTestBundle), então os testes não interferem entre si.
// Defina SKIP_TEST_DB_RESET=1 para reaproveitar o banco quando estiver iterando rápido.
if (!getenv('SKIP_TEST_DB_RESET')) {
    $console = escapeshellarg(dirname(__DIR__).'/bin/console');
    foreach ([
        'doctrine:database:drop --if-exists --force',
        'doctrine:database:create',
        'doctrine:migrations:migrate --no-interaction --allow-no-migration',
    ] as $command) {
        passthru(sprintf('php %s %s --env=test --quiet', $console, $command), $exitCode);
        if (0 !== $exitCode) {
            fwrite(STDERR, "Falha ao preparar o banco de testes: {$command}\n");
            exit(1);
        }
    }
}
