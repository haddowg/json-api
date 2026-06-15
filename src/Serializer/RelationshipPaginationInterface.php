<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;

/**
 * Storage-aware resolver that supplies the page-1 pagination state for a rendered
 * to-many relation under the Relationship Queries profile — the
 * `first` / `prev` / `next` (+ `last` when countable) links core renders in the
 * relationship's own `links` object.
 *
 * Core never paginates a relationship: the page-1 window (ordered/filtered by the
 * profile's per-relationship sort/filter) is storage-specific and is the host's
 * to compute through its data layer and paginator. The adapter (e.g. a Doctrine
 * bundle) windows the relation to page 1, reads the request's
 * {@see JsonApiRequestInterface::getRelatedQuery()} for the relation, builds a
 * {@see RelationshipPagination} (page + the plain-form query string from
 * {@see \haddowg\JsonApi\Request\RelatedQuery::toPlainQueryString()}), and returns
 * it; core attaches it to the built relationship so {@see \haddowg\JsonApi\Schema\Relationship\AbstractRelationship::transform()}
 * emits the plain-form endpoint links.
 *
 * Consulted only for a to-many relation when the Relationship Queries profile is
 * negotiated. Core ships no implementation: with no resolver injected (standalone
 * library) no relationship-object pagination links are emitted, exactly as before
 * this seam existed.
 *
 * Injected through the {@see \haddowg\JsonApi\Resource\SerializerResolverInterface},
 * mirroring {@see RelationshipCountInterface} / {@see RelationshipLoadStateInterface}.
 */
interface RelationshipPaginationInterface
{
    /**
     * Returns the page-1 pagination state for `$relation` on `$model`, or `null`
     * when the relation is not paginated for this request (no profile-supplied
     * query and no default relation pagination, or no page available) — in which
     * case core emits no relationship-object pagination links.
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
