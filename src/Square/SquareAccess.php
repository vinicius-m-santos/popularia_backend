<?php

declare(strict_types=1);

namespace App\Square;

use App\Api\ApiProblem;
use App\Content\ComunaModerationPolicy;
use App\Content\ContentPolicy;
use App\Entity\Square;
use App\Entity\SquareMessage;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Repository\ComunaMembershipRepository;
use App\Repository\UserBlockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class SquareAccess
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContentPolicy $content,
        public ComunaModerationPolicy $moderation,
        public ComunaMembershipRepository $memberships,
        public UserBlockRepository $blocks,
    ) {
    }

    public function find(string $id): Square
    {
        return (Uuid::isValid($id) ? $this->em->find(Square::class, Uuid::fromString($id)) : null)
            ?? throw ApiProblem::notFound('Praça não encontrada.');
    }

    public function canRead(Square $square, User $viewer): bool
    {
        // Independentes não são publicadas pela V1: negar por padrão até existir política própria.
        return null !== $square->comuna && $this->content->canReadComuna($square->comuna, $viewer);
    }

    public function read(Square $square, User $viewer): void
    {
        if (!$this->canRead($square, $viewer)) {
            throw ApiProblem::forbidden('/errors/square-private', 'Esta Praça pertence a uma comuna privada.');
        }
    }

    public function active(Square $square): void
    {
        if ('active' !== $square->status) {
            throw ApiProblem::conflict('/errors/square-closed', 'Esta Praça está fechada no momento.');
        }
        if (null !== $square->comuna) {
            $this->moderation->assertNotArchived($square->comuna);
        }
    }

    public function write(Square $square, User $viewer): void
    {
        $this->read($square, $viewer);
        $this->active($square);
        if (!$viewer->isEmailVerified()) {
            throw ApiProblem::emailNotVerified();
        }
        if (null === $square->comuna || null === $this->memberships->findMembership($square->comuna, $viewer)) {
            throw ApiProblem::forbidden('/errors/not-a-member', 'Participe da comuna para conversar na Praça.');
        }
    }

    public function manage(Square $square, User $viewer): void
    {
        $comuna = $square->comuna ?? throw ApiProblem::notFound();
        $this->moderation->assertModerator($comuna, $viewer);
        $this->moderation->assertNotArchived($comuna);
    }

    public function message(string $id, Square $square, User $viewer, bool $published = true): SquareMessage
    {
        $this->read($square, $viewer);
        $message = Uuid::isValid($id) ? $this->em->find(SquareMessage::class, Uuid::fromString($id)) : null;
        if (null === $message || !$message->square->id->equals($square->id)
            || ($published && ContentStatus::Published !== $message->status)
            || !$message->author->isActive()
            || $this->blocks->isBlockedEitherWay($viewer, $message->author)) {
            throw ApiProblem::notFound('Mensagem indisponível.');
        }

        return $message;
    }
}
