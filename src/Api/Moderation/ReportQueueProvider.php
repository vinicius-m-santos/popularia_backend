<?php

declare(strict_types=1);

namespace App\Api\Moderation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Content\ReportModeration;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;

/** @implements ProviderInterface<ReportQueue> */
final readonly class ReportQueueProvider implements ProviderInterface
{
    public function __construct(
        private ReportModeration $moderation,
        private ComunaRepository $comunas,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReportQueue
    {
        $actor = $this->currentUser->get();
        $page = Pagination::fromContext($context);
        $bucket = $this->bucket($context);
        if ('platform_reports' === $operation->getName()) {
            return $this->moderation->queueForPlatform($actor, $bucket, $page);
        }
        $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');

        return $this->moderation->queueForComuna($comuna, $actor, $bucket, $page);
    }

    /** @param array<string, mixed> $context */
    private function bucket(array $context): string
    {
        $request = $context['request'] ?? null;
        $bucket = $request instanceof Request ? (string) $request->query->get('bucket', 'open') : 'open';

        return '' === $bucket ? 'open' : $bucket;
    }
}
