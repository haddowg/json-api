<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Data;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Pagination\PageInterface;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;

/**
 * The catalog's data layer over the shared {@see InMemoryStore}. Read methods
 * apply the request criteria via {@see CriteriaApplier} and, when a paginator is
 * supplied, run the push-down window → slice → count → paginate loop the library's
 * two-method {@see PaginatorInterface} is designed for. Write methods mutate the
 * store directly, so a create is immediately readable.
 *
 * One repository per Server.
 */
final class InMemoryRepository
{
    public function __construct(
        private readonly InMemoryStore $store,
        private readonly CriteriaApplier $criteria,
    ) {}

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->store->find($type, $id);
    }

    /**
     * Every stored row of a type, in insertion order, with no criteria applied —
     * the collection path for a standalone bare serializer (e.g. `charts`) that
     * carries no filter/sort/pagination metadata.
     *
     * @return list<object>
     */
    public function fetchAll(string $type): array
    {
        return $this->store->all($type);
    }

    /**
     * Fetch a (possibly paginated) collection: load every row of the type, apply
     * filters + sorts, then either return the whole filtered list or run the
     * window → slice → count → paginate push-down loop.
     *
     * @return PageInterface<mixed>|list<object>
     */
    public function fetchCollection(
        string $type,
        AbstractResource $resource,
        QueryParameters $query,
        JsonApiRequestInterface $request,
        ?PaginatorInterface $paginator,
    ): PageInterface|array {
        $filtered = $this->criteria->apply($this->store->all($type), $resource, $request);

        return $this->window($filtered, $request, $paginator);
    }

    /**
     * Fetch a (possibly paginated) related to-many collection from an
     * already-resolved related list. A monomorphic relation applies the shared
     * criteria + window; a polymorphic relation carries no shared filter/sort
     * vocabulary, so it only windows (the caller signals this with $applyCriteria
     * = false).
     *
     * @param iterable<object> $related
     *
     * @return PageInterface<mixed>|list<object>
     */
    public function fetchRelatedCollection(
        iterable $related,
        ?AbstractResource $relatedResource,
        QueryParameters $query,
        JsonApiRequestInterface $request,
        ?PaginatorInterface $paginator,
        bool $applyCriteria = true,
    ): PageInterface|array {
        $rows = \is_array($related) ? \array_values($related) : \iterator_to_array($related, false);

        if ($applyCriteria && $relatedResource !== null) {
            // A related sub-collection applies only the filters/sorts the client
            // actually requested — never the related resource's primary-collection
            // filter defaults (so GET /albums/1/tracks is the album's full track
            // set, not the default-filtered primary view).
            $rows = $this->criteria->apply($rows, $relatedResource, $request, foldDefaults: false);
        }

        return $this->window($rows, $request, $paginator);
    }

    /**
     * The push-down loop: ask the paginator for the fetch window, slice exactly
     * that window, count the whole filtered collection separately, and hand both
     * to {@see PaginatorInterface::paginate()}. With no paginator, return the full
     * filtered list.
     *
     * @param list<object> $rows
     *
     * @return PageInterface<mixed>|list<object>
     */
    private function window(array $rows, JsonApiRequestInterface $request, ?PaginatorInterface $paginator): PageInterface|array
    {
        if ($paginator === null) {
            return $rows;
        }

        $window = $paginator->window($request);
        $total = \count($rows);

        $slice = $window instanceof OffsetWindow
            ? \array_slice($rows, $window->offset, $window->limit)
            : $rows;

        return $paginator->paginate($request, $slice, $total);
    }

    public function create(string $type, object $entity, string $id): void
    {
        $this->store->put($type, $id, $entity);
    }

    public function update(string $type, object $entity, string $id): void
    {
        $this->store->put($type, $id, $entity);
    }

    public function delete(string $type, string $id): void
    {
        $this->store->remove($type, $id);
    }
}
