<?php

declare(strict_types=1);

namespace App\Command;

use App\Auth\AdminAudit;
use App\Auth\SessionManager;
use App\Content\ComunaPins;
use App\Entity\Comment;
use App\Entity\ContentReport;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/** Operação mínima do beta; Biblioteca e Praça mantêm suas ferramentas existentes. */
#[AsCommand(name: 'app:reports:review', description: 'Lista até 100 denúncias pendentes; decide post/comment/profile por UUID, dismissed ou removed e motivo.')]
final readonly class ReviewReportsCommand
{
    public function __construct(private EntityManagerInterface $em, private AdminAudit $audit, private SessionManager $sessions, private ClockInterface $clock, private ComunaPins $pins)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument] ?string $id = null, #[Argument] ?string $decision = null, #[Argument] ?string $reason = null): int
    {
        if (null === $id) {
            $reports = $this->em->getRepository(ContentReport::class)->findBy(['status' => 'pending'], ['createdAt' => 'ASC'], 100);
            $io->table(['ID', 'Tipo', 'Alvo', 'Motivo'], array_map(static fn (ContentReport $r) => [$r->id->toRfc4122(), $r->targetType, $r->targetId->toRfc4122(), $r->reason], $reports));

            return Command::SUCCESS;
        }
        $report = Uuid::isValid($id) ? $this->em->find(ContentReport::class, Uuid::fromString($id)) : null;
        if (null === $report || !in_array($report->targetType, ['post', 'comment', 'profile'], true) || !in_array($decision, ['dismissed', 'removed'], true) || mb_strlen(trim($reason ?? '')) < 5) {
            $io->error('Informe denúncia de post/comment/profile, dismissed|removed e motivo de pelo menos 5 caracteres. Use as ferramentas existentes para Praça e Biblioteca.');

            return Command::INVALID;
        }
        if ('pending' !== $report->status) {
            $io->note('Denúncia já decidida.');

            return Command::SUCCESS;
        }
        $this->em->getConnection()->transactional(function () use ($report, $decision, $reason): void {
            if ('removed' === $decision) {
                $target = $this->em->find(match ($report->targetType) {
                    'post' => Post::class, 'comment' => Comment::class, default => User::class,
                }, $report->targetId);
                if ($target instanceof User && AccountStatus::Deleted !== $target->getStatus()) {
                    $target->changeStatus(AccountStatus::Suspended, $this->clock->now());
                    $this->sessions->revokeAll($target, 'moderation');
                } elseif ($target instanceof Post || $target instanceof Comment) {
                    $target->removeByModeration();
                    if ($target instanceof Post) {
                        $this->pins->release($target);
                    }
                }
            }
            $report->status = (string) $decision;
            $report->resolvedAt = $this->clock->now();
            $this->em->flush();
            $this->audit->record(null, 'report.resolved', null, (string) $reason, ['report_id' => $report->id->toRfc4122(), 'status' => $decision]);
        });
        $io->success('Denúncia decidida e ação auditada.');

        return Command::SUCCESS;
    }
}
