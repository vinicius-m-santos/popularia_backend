<?php

declare(strict_types=1);

// Diagnóstico reproduzível exclusivamente no banco *_test; tudo é revertido.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel('test', true);
$kernel->boot();
$original = $kernel->getContainer()->get('test.service_container')->get(Doctrine\ORM\EntityManagerInterface::class);
$logger = new class extends Psr\Log\AbstractLogger {
    public array $queries = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql']) && str_starts_with($context['sql'], 'SELECT')) {
            $this->queries[] = $context;
        }
    }
};
$config = new Doctrine\DBAL\Configuration();
$config->setMiddlewares([new Doctrine\DBAL\Logging\Middleware($logger)]);
$params = $original->getConnection()->getParams();
unset($params['driverClass']);
$db = Doctrine\DBAL\DriverManager::getConnection($params, $config);
if (!str_ends_with((string) $db->fetchOne('SELECT current_database()'), '_test')) {
    throw new RuntimeException('Somente banco de testes.');
}
$em = new Doctrine\ORM\EntityManager($db, $original->getConfiguration());
$db->beginTransaction();
try {
    $db->executeStatement('CREATE TEMP TABLE audit_users AS SELECT n, gen_random_uuid() AS id FROM generate_series(1,5000) n');
    $db->executeStatement("INSERT INTO app_user (id,email,password,handle,display_name,created_at,updated_at,is_private,status) SELECT id, 'audit'||n||'@example.test', '', 'audit'||n, CASE WHEN n%50=0 THEN 'Educação '||n ELSE 'Pessoa '||n END, now(), now(), n%7=0, CASE WHEN n%11=0 THEN 'suspended' ELSE 'active' END FROM audit_users");
    $db->executeStatement('CREATE TEMP TABLE audit_comunas AS SELECT n, gen_random_uuid() AS id FROM generate_series(1,500) n');
    $db->executeStatement("INSERT INTO comuna (id,slug,name,description,place,visibility,tone,topics,founded_year,created_at) SELECT id, 'audit'||n, CASE WHEN n%10=0 THEN 'Educação '||n ELSE 'Comuna '||n END, 'Comunidade de teste', 'São Paulo', CASE WHEN n%4=0 THEN 'private' ELSE 'public' END, 'red', '[\"edu\"]', 2026, now() FROM audit_comunas");
    $db->executeStatement("INSERT INTO post (id,status,body,author_id,comuna_id,created_at) SELECT gen_random_uuid(), 'published', CASE WHEN n%4=0 THEN 'Educação pública de qualidade para todas as pessoas #educacao' ELSE 'Conversa da comunidade sobre assuntos cotidianos' END, (SELECT id FROM audit_users WHERE audit_users.n=(series.n%5000)+1), CASE WHEN n%3=0 THEN NULL ELSE (SELECT id FROM audit_comunas WHERE audit_comunas.n=(series.n%500)+1) END, now()-n*interval '1 minute' FROM generate_series(1,20000) AS series(n)");
    foreach (['app_user', 'comuna', 'post'] as $table) {
        $db->executeStatement('ANALYZE '.$table);
    }
    $viewer = $em->find(App\Entity\User::class, $db->fetchOne('SELECT id FROM audit_users WHERE n=1'));
    $search = new App\Discovery\DiscoveryQuery($em, new Symfony\Component\Clock\NativeClock());
    $report = ['fixture' => ['users' => 5000, 'comunas' => 500, 'posts' => 20000], 'queries' => []];
    foreach (['people', 'comunas', 'posts', 'discovery-recent'] as $kind) {
        $logger->queries = [];
        $start = microtime(true);
        if ('discovery-recent' === $kind) {
            $search->find('posts', $viewer, '', null, null, 20, false, true);
        } else {
            $search->search($kind, $viewer, 'educacao', null, null, 20);
        }
        $elapsed = (microtime(true) - $start) * 1000;
        $queries = $logger->queries;
        $selection = $queries[0];
        $plan = $db->executeQuery('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$selection['sql'], array_values($selection['params'] ?? []), array_values($selection['types'] ?? []))->fetchOne();
        $report['queries'][$kind] = ['selection_and_hydration_ms' => round($elapsed, 3), 'sql_count' => count($queries), 'selection_sql' => $selection['sql'], 'plan' => json_decode($plan, true, flags: JSON_THROW_ON_ERROR)];
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
} finally {
    $db->rollBack();
    $kernel->shutdown();
}
