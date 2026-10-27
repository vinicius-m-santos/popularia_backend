<?php

declare(strict_types=1);

namespace App\Api\Library;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Entity\LibrarySave;
use App\Library\CollectionQueries;
use App\Library\LibraryAccess;
use App\Library\LibraryQueries;
use App\Library\LibraryViews;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/** @implements ProviderInterface<DocumentView|DocumentPage|CollectionView|CollectionPage> */
final readonly class LibraryProvider implements ProviderInterface
{
    public function __construct(private CurrentUser $currentUser, private LibraryAccess $access, private LibraryQueries $queries, private LibraryViews $views, private CollectionQueries $collections, private EntityManagerInterface $em)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DocumentView|DocumentPage|CollectionView|CollectionPage
    {
        $viewer = $this->currentUser->get();
        $request = $context['request'] ?? new Request();
        \assert($request instanceof Request);
        $page = in_array($operation->getName(), ['library_documents'], true) ? \App\Library\LibraryCursor::fromContext($context) : Pagination::fromContext($context);
        $name = $operation->getName();
        if ('library_document' === $name) {
            $document = $this->access->find((string) $uriVariables['id']);
            $view = $this->views->one($document, $viewer);
            if ('available' !== $view->state && null === $view->title) {
                throw ApiProblem::notFound('Este documento não está mais disponível para você.');
            }

            return $view;
        }
        if ('library_collection' === $name || 'library_collections' === $name) {
            [$items, $next] = $this->collections->page($viewer, $request, $page, 'library_collection' === $name ? (string) $uriVariables['id'] : null);

            return 'library_collection' === $name ? ($items[0] ?? throw ApiProblem::notFound('Coleção não encontrada.')) : new CollectionPage($items, $next);
        }
        if ('library_saved' === $name) {
            $qb = $this->em->createQueryBuilder()->select('s', 'd', 'f', 'col', 'a')->from(LibrarySave::class, 's')
                ->join('s.document', 'd')->join('d.file', 'f')->join('d.collection', 'col')->join('f.uploadedBy', 'a')
                ->where('s.user = :viewer')->setParameter('viewer', $viewer)->orderBy('s.id', 'DESC')->setMaxResults($page->limit + 1);
            if (null !== $page->cursor) {
                $qb->andWhere('s.id < :cursor')->setParameter('cursor', $page->cursor, 'uuid');
            }
            /** @var list<LibrarySave> $rows */
            $rows = $qb->getQuery()->getResult();
            [$rows, $next] = $page->slice($rows, static fn (LibrarySave $s) => $s->id);
            $items = $this->views->many(array_map(static fn (LibrarySave $s) => $s->document, $rows), $viewer);

            return new DocumentPage($items, $next);
        }
        [$documents, $next] = $this->queries->documents($viewer, $request, $page);

        return new DocumentPage($this->views->many($documents, $viewer), $next);
    }
}
