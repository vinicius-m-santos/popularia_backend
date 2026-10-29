<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Api\ApiProblem;
use App\Content\ContentPolicy;
use App\Entity\Poll;
use App\Entity\PollOption;
use App\Entity\PollVote;
use App\Repository\PollVoteRepository;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Um voto por pessoa. A linha da enquete é travada na transação, então dois pedidos
 * simultâneos da mesma conta esperam um ao outro e não criam dois votos. O índice
 * único (poll_id, user_id) é a garantia restante se a trava falhar.
 *
 * @implements ProcessorInterface<PollVoteInput, PostView>
 */
final readonly class PollVoteProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ContentPolicy $policy,
        private PollVoteRepository $votes,
        private PostViews $views,
        private RateLimitGuard $rateLimit,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PostView
    {
        $voter = $this->currentUser->get();
        $post = $this->policy->findVisiblePost((string) ($uriVariables['id'] ?? ''), $voter);
        if (null !== $problem = $this->policy->whyCannotVote($post, $voter)) {
            throw $problem;
        }
        $poll = $post->getPoll() ?? throw ApiProblem::badRequest('/errors/no-poll', 'Esta publicação não tem enquete.');
        $this->rateLimit->consume('poll_vote', $voter->getUserIdentifier());

        $this->em->wrapInTransaction(function () use ($poll, $voter, $data): void {
            $this->em->lock($poll, LockMode::PESSIMISTIC_WRITE);
            $option = $this->optionOf($poll, $data->optionId) ?? throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('Escolha uma opção desta enquete.', null, [], $data, 'optionId', $data->optionId)]));
            $existing = $this->votes->findForUser($poll, $voter);
            if (null !== $existing) {
                if ($existing->getOption()->getId()->equals($option->getId())) {
                    return;
                }
                $this->shift($existing->getOption(), -1);
                $existing->moveTo($option);
                $this->shift($option, 1);

                return;
            }
            $this->em->persist(new PollVote($poll, $option, $voter));
            $this->shift($option, 1);
        });

        foreach ($poll->getOptions() as $option) {
            $this->em->refresh($option);
        }

        return $this->views->one($post, $voter);
    }

    private function optionOf(Poll $poll, string $optionId): ?PollOption
    {
        if (!Uuid::isValid($optionId)) {
            return null;
        }
        foreach ($poll->getOptions() as $option) {
            if ($option->getId()->toRfc4122() === $optionId) {
                return $option;
            }
        }

        return null;
    }

    /** Incremento atômico. A trava da enquete já serializa os votos; o SQL evita perder contagem. */
    private function shift(PollOption $option, int $delta): void
    {
        $updated = $this->em->getConnection()->executeStatement(
            'UPDATE poll_option SET vote_count = vote_count + :delta WHERE id = :id AND vote_count + :delta >= 0',
            ['delta' => $delta, 'id' => $option->getId()->toRfc4122()],
        );
        if (1 !== $updated) {
            throw ApiProblem::conflict('/errors/poll-vote', 'Não foi possível registrar o voto. Tente de novo.');
        }
    }
}
