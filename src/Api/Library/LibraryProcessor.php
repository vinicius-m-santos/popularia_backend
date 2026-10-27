<?php

declare(strict_types=1);

namespace App\Api\Library;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Pagination;
use App\Library\CollectionQueries;
use App\Library\LibraryService;
use App\Library\LibraryViews;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;

/** @implements ProcessorInterface<mixed, DocumentView|CollectionView|null> */
final readonly class LibraryProcessor implements ProcessorInterface
{
    public function __construct(private CurrentUser $currentUser, private LibraryService $service, private LibraryViews $views, private CollectionQueries $collections)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DocumentView|CollectionView|null
    {
        $actor = $this->currentUser->get();
        $id = (string) ($uriVariables['id'] ?? '');
        $slug = (string) ($uriVariables['slug'] ?? '');
        $name = $operation->getName();
        if ('library_edit' === $name) {
            \assert($data instanceof DocumentInput);

            return $this->views->one($this->service->edit($id, $actor, $data), $actor);
        }
        if (in_array($name, ['library_create_collection', 'library_edit_collection'], true)) {
            \assert($data instanceof CollectionInput);
            $collection = 'library_create_collection' === $name ? $this->service->createCollection($actor, $data) : $this->service->changeCollection($id, $actor, $data);
            \assert(null !== $collection);

            return $this->collections->page($actor, new Request(), Pagination::fromContext([]), $collection->id->toRfc4122())[0][0];
        }
        if ('library_delete_collection' === $name) {
            $this->service->changeCollection($id, $actor, null);
        } elseif ('library_delete' === $name) {
            $this->service->delete($id, $actor);
        } elseif ('library_save' === $name || 'library_unsave' === $name) {
            $this->service->save($id, $actor, 'library_save' === $name);
        } elseif ('library_share' === $name || 'library_unshare' === $name) {
            $this->service->share($id, $slug, $actor, 'library_share' === $name);
        } elseif ('library_report' === $name || 'library_remove' === $name) {
            \assert($data instanceof ReasonInput);
            if ('library_report' === $name) {
                $this->service->report($id, $actor, $data->reason, $data->comunaSlug);
            } else {
                $this->service->remove($id, $actor, $data->reason, $data->comunaSlug);
            }
        }

        return null;
    }
}
