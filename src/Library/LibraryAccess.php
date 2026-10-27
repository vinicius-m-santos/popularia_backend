<?php

declare(strict_types=1);

namespace App\Library;

use App\Api\ApiProblem;
use App\Content\ComunaModerationPolicy;
use App\Entity\LibraryCollection;
use App\Entity\LibraryDocument;
use App\Entity\User;
use App\Repository\ComunaMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** A mesma expressão de acesso protege listas, busca, detalhes e download ANTES dos DTOs. */
final readonly class LibraryAccess
{
    public const string FROM = 'library_document d JOIN library_file f ON f.id = d.file_id JOIN app_user a ON a.id = f.uploaded_by_id JOIN library_collection col ON col.id = d.collection_id LEFT JOIN comuna c ON c.id = d.comuna_id';

    public function __construct(
        private EntityManagerInterface $em,
        public ComunaMembershipRepository $memberships,
        public ComunaModerationPolicy $moderation,
    ) {
    }

    public static function readable(): string
    {
        return self::base()." AND (d.is_public OR d.user_id = :viewer
            OR (d.user_id IS NOT NULL AND d.access = 'followers' AND EXISTS (SELECT 1 FROM user_follow uf WHERE uf.follower_id = :viewer AND uf.followed_id = d.user_id AND uf.approved))
            OR (c.id IS NOT NULL AND ((c.visibility = 'public' AND d.access = 'visitors') OR EXISTS (SELECT 1 FROM comuna_membership m WHERE m.comuna_id = c.id AND m.user_id = :viewer AND (d.access <> 'moderators' OR m.role IN ('founder', 'moderator')))))
            OR EXISTS (SELECT 1 FROM library_share s JOIN comuna sc ON sc.id = s.comuna_id JOIN comuna_membership sm ON sm.id = s.membership_id WHERE s.document_id = d.id AND s.status = 'published' AND sm.comuna_id = s.comuna_id AND sm.user_id = d.user_id AND (sc.visibility = 'public' OR EXISTS (SELECT 1 FROM comuna_membership vm WHERE vm.comuna_id = sc.id AND vm.user_id = :viewer))))";
    }

    public static function visibleCard(): string
    {
        return '('.self::readable().') OR ('.self::base()." AND c.visibility = 'public' AND d.access = 'members')";
    }

    private static function base(): string
    {
        return "d.status = 'published' AND f.status = 'ready' AND (a.status = 'active' OR (a.status = 'deleted' AND d.comuna_id IS NOT NULL)) AND NOT EXISTS (SELECT 1 FROM user_block b WHERE (b.blocker_id = :viewer AND b.blocked_id = a.id) OR (b.blocked_id = :viewer AND b.blocker_id = a.id))";
    }

    public function find(string $id): LibraryDocument
    {
        return (Uuid::isValid($id) ? $this->em->find(LibraryDocument::class, Uuid::fromString($id)) : null) ?? throw ApiProblem::notFound('Documento não encontrado.');
    }

    public function canRead(LibraryDocument $document, ?User $viewer): bool
    {
        if (null === $viewer) {
            return false;
        }

        return false !== $this->em->getConnection()->executeQuery('SELECT d.id FROM '.self::FROM.' WHERE d.id = :id AND ('.self::readable().')', ['id' => $document->id->toRfc4122(), 'viewer' => $viewer->getId()->toRfc4122()])->fetchOne();
    }

    public function assertRead(LibraryDocument $document, User $viewer): void
    {
        if (!$this->canRead($document, $viewer)) {
            throw ApiProblem::notFound('Este documento não está mais disponível para você.');
        }
    }

    public function managesCollection(LibraryCollection $collection, User $viewer): bool
    {
        if (null !== $collection->user) {
            return $collection->user->getId()->equals($viewer->getId());
        }
        $membership = null === $collection->comuna ? null : $this->memberships->findMembership($collection->comuna, $viewer);

        return null !== $membership && ($membership->getRole()->canModerate() || $collection->createdBy->getId()->equals($viewer->getId()));
    }

    public function manages(LibraryDocument $document, User $viewer): bool
    {
        if (null !== $document->user) {
            return $document->user->getId()->equals($viewer->getId());
        }
        $membership = null === $document->comuna ? null : $this->memberships->findMembership($document->comuna, $viewer);

        return null !== $membership && ($membership->getRole()->canModerate() || $document->file->uploadedBy->getId()->equals($viewer->getId()));
    }

    public function publicizes(LibraryDocument $document, User $viewer): bool
    {
        if (null !== $document->user) {
            return $document->user->getId()->equals($viewer->getId());
        }
        $membership = null === $document->comuna ? null : $this->memberships->findMembership($document->comuna, $viewer);

        return null !== $membership && $membership->getRole()->canModerate();
    }

    public function assertManage(LibraryDocument $document, User $viewer, bool $administrative = false): void
    {
        if (!$this->manages($document, $viewer)) {
            throw ApiProblem::notFound('Documento não encontrado.');
        }
        if (null !== $document->comuna && (!$administrative || !$this->publicizes($document, $viewer))) {
            $this->moderation->assertNotArchived($document->comuna);
        }
    }
}
