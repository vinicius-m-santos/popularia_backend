<?php

declare(strict_types=1);

namespace App\Api\Health;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/** @implements ProviderInterface<Health> */
final readonly class HealthProvider implements ProviderInterface
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Health
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $e) {
            // A mensagem do driver pode conter host e usuário: só a classe vai para o log.
            $this->logger->error('Health check: banco de dados indisponível.', ['exception_class' => $e::class]);
            throw ApiProblem::serviceUnavailable();
        }

        return new Health('ok', 'ok');
    }
}
