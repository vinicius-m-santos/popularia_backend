<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Poll;
use App\Entity\PollVote;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PollVote> */
class PollVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PollVote::class);
    }

    public function findForUser(Poll $poll, User $user): ?PollVote
    {
        return $this->findOneBy(['poll' => $poll, 'user' => $user]);
    }

    /**
     * Voto de quem vê, numa consulta só, para a página inteira de publicações.
     *
     * @param list<Poll> $polls
     *
     * @return array<string, string> poll id => option id, ambos RFC 4122
     */
    public function choicesFor(User $user, array $polls): array
    {
        if ([] === $polls) {
            return [];
        }
        /** @var list<array{pollId: string, optionId: string}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.poll) AS pollId', 'IDENTITY(v.option) AS optionId')
            ->where('v.user = :user')
            ->andWhere('v.poll IN (:polls)')
            ->setParameter('user', $user)
            ->setParameter('polls', $polls)
            ->getQuery()
            ->getArrayResult();

        $choices = [];
        foreach ($rows as $row) {
            $choices[(string) $row['pollId']] = (string) $row['optionId'];
        }

        return $choices;
    }
}
