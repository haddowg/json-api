<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;

/**
 * Storage-aware resolver that supplies the first-page pagination state of a rendered
 * to-many relation: the `first` / `prev` / `next` / `last` links core renders in the
 * relationship's own `links` object, and the total of a counted page, which core
 * renders as a countable relation's `meta.total`.
 *
 * Core never reads a relationship's members itself: the window is storage-specific
 * and the host's to compute through its data layer and paginator. Wherever a
 * to-many's linkage `data` renders, included or declared `withData()`, a host cuts it
 * to the first page under {@see \haddowg\JsonApi\Server\Server::linkagePaginator()},
 * ordered and narrowed like its related endpoint and by any `relatedQuery` the request
 * carries ({@see JsonApiRequestInterface::getRelatedQuery()}), supplies those members
 * through {@see RelationshipLinkageInterface}, and returns the matching
 * {@see RelationshipPagination} here (built with
 * {@see RelationshipPagination::firstPage()}).
 *
 * Core ships no implementation: with no resolver injected (standalone library) no
 * relationship-object pagination links are emitted.
 *
 * Injected through the {@see \haddowg\JsonApi\Resource\SerializerResolverInterface},
 * mirroring {@see RelationshipCountInterface} / {@see RelationshipLoadStateInterface}.
 */
interface RelationshipPaginationInterface
{
    /**
     * Returns the first-page pagination state for `$relation` on `$model`, or `null`
     * when its linkage was not cut to a page for this request, in which case core
     * emits no relationship-object pagination links.
     *
     * Implementations must window to page 1 only (never materialize the whole
     * relationship). The `$relation` carries the cardinality
     * ({@see RelationInterface::isToMany()}), countability
     * ({@see RelationInterface::isCountable()} — drives whether the page emits
     * `last`), and the backing column; the `$request` carries the per-relationship
     * sort/filter via {@see JsonApiRequestInterface::getRelatedQuery()}.
     *
     * @return RelationshipPagination|null
     */
    public function paginateRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?RelationshipPagination;
}
