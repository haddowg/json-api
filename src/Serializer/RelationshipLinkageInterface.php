<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;

/**
 * Storage-aware resolver that supplies a rendered to-many relation's linkage `data`
 * OUT-OF-BAND — so core renders the supplied page rather than reading the linkage
 * off the parent model's backing property.
 *
 * Its motivating consumer is the Relationship Queries profile's per-page window: the
 * host windows a to-many to page 1 of its profile sort/filter and supplies that page
 * here, keyed per (parent model, relation), INSTEAD of writing it back onto the
 * parent property. The write-back is destructive — core reads every relation's
 * linkage off its backing column, so a windowed relation's filtered page would
 * overwrite the column a SIBLING relation sharing it also reads, leaking the
 * windowed page onto a relation the client never addressed (and, under a lazy
 * load-state predicate, flipping it to "loaded" so it emits a `data` member the lazy
 * default would have omitted). Supplying the page through this seam leaves the
 * column untouched, so a shared-column bystander renders its own membership.
 *
 * Consulted for a to-many relation whose linkage data renders (included, or
 * {@see RelationInterface::emitsDataOnlyWhenLoaded()} is false). When it returns a
 * non-null {@see RelationshipLinkage}, that data is used as the relationship's
 * linkage (eagerly, always emitting a `data` member); when it returns `null` the
 * relation falls back to reading the value off the parent as before. Core ships no
 * implementation: with none injected (standalone library) linkage is always read off
 * the model, exactly as before this seam existed.
 *
 * Injected through the {@see \haddowg\JsonApi\Resource\SerializerResolverInterface},
 * mirroring {@see RelationshipPaginationInterface} / {@see RelationshipCountInterface}
 * / {@see RelationshipLoadStateInterface}.
 */
interface RelationshipLinkageInterface
{
    /**
     * Returns the out-of-band linkage `data` for `$relation` on `$model`, or `null`
     * when this resolver supplies none for the (parent, relation) — in which case
     * core reads the linkage off the parent model's backing property as before.
     *
     * The `$relation` carries the cardinality ({@see RelationInterface::isToMany()})
     * and backing column; the `$request` carries the per-relationship sort/filter via
     * {@see JsonApiRequestInterface::getRelatedQuery()}.
     */
    public function linkageFor(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?RelationshipLinkage;
}
