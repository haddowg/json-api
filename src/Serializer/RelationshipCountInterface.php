<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Storage-aware resolver that supplies the cardinality of a countable to-many
 * relation for a given parent model — the `meta.total` core renders on the
 * relationship object when the request names the relation in `?withCount`.
 *
 * Core never computes a count: it is storage-specific (a pushed-down `COUNT(…)`,
 * a counted in-memory collection) and — to avoid an N+1 on a collection render —
 * batched across the whole fetched page of parents before render. A data-layer
 * adapter (e.g. a Doctrine bundle) computes the counts and supplies them through
 * this seam, keyed per (parent model, relation); core only reads one back and
 * renders it.
 *
 * Consulted only for a relation that is {@see RelationInterface::isCountable()}
 * and named in the request's `?withCount`. Core ships no implementation: with no
 * resolver injected (standalone library) no `meta.total` is emitted even for a
 * countable, `?withCount`-named relation, exactly as before this seam existed.
 *
 * Injected through the {@see \haddowg\JsonApi\Resource\SerializerResolverInterface},
 * mirroring {@see RelationshipLoadStateInterface}.
 */
interface RelationshipCountInterface
{
    /**
     * Returns the total number of related items for `$relation` on `$model`, or
     * `null` when no count is available for this (parent, relation) — in which
     * case core omits `meta.total` rather than emitting a guessed or zero value.
     *
     * Implementations must not trigger a full materialization of the collection
     * to answer: the count is expected to be pushed down (or pre-batched). The
     * `$relation` carries the cardinality
     * ({@see RelationInterface::isToMany()}) and the backing column
     * ({@see \haddowg\JsonApi\Resource\Field\FieldInterface::column()}) the
     * adapter needs to map the JSON:API relationship to its storage association.
     */
    public function countRelationship(mixed $model, RelationInterface $relation): ?int;
}
