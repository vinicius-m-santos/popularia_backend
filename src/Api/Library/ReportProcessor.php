<?php

declare(strict_types=1);

namespace App\Api\Library;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Api\Square\ReportDecisionInput;
use App\Auth\AdminAudit;
use App\Content\ComunaModerationPolicy;
use App\Entity\ContentReport;
use App\Library\LibraryService;
use App\Security\CurrentUser;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<ReportDecisionInput, null> */
final readonly class ReportProcessor implements ProcessorInterface
{
    public function __construct(private CurrentUser $user, private EntityManagerInterface $em, private LibraryService $library, private ComunaModerationPolicy $moderation, private ClockInterface $clock, private AdminAudit $audit)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->em->getConnection()->transactional(function () use ($data, $uriVariables): void {
            $actor = $this->user->get();
            $id = (string) ($uriVariables['id'] ?? '');
            $report = Uuid::isValid($id) ? $this->em->find(ContentReport::class, Uuid::fromString($id)) : null;
            if (null === $report || 'library_document' !== $report->targetType) {
                throw ApiProblem::notFound();
            }
            if (!in_array($actor->getPlatformRole()->value, ['admin', 'platform_moderator'], true)) {
                $this->moderation->assertModerator($report->comuna ?? throw ApiProblem::notFound(), $actor);
            }
            $this->em->refresh($report, LockMode::PESSIMISTIC_WRITE);
            if ('pending' !== $report->status) {
                return;
            }
            if ('removed' === $data->status) {
                $this->library->remove($report->targetId->toRfc4122(), $actor, $report->reason, $report->comuna?->getSlug());
            }
            $report->status = $data->status;
            $report->resolvedAt = $this->clock->now();
            $this->em->flush();
            $this->audit->record($actor, 'library.report_resolved', null, $report->reason, ['report_id' => $report->id->toRfc4122(), 'status' => $data->status]);
        });

        return null;
    }
}
