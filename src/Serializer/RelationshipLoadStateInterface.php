<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Storage-aware predicate that answers, **without triggering any load**, whether
 * a relation's linkage data is already in memory (and therefore cheap to emit)
 * for a given parent model.
 *
 * Core ships no implementation: the standalone library is storage-agnostic and
 * has no way to know what is or isn't loaded, so when no predicate is injected
 * the {@see \haddowg\JsonApi\Server\Server} treats every relation as loaded and
 * emits linkage data exactly as it always has. A data-layer adapter (e.g. a
 * Doctrine bundle) supplies an implementation that inspects its unit of work —
 * a {@see \Doctrine\ORM\PersistentCollection}'s `isInitialized()`, an
 * uninitialised proxy, an unhydrated foreign key — and reports the answer
 * cheaply.
 *
 * Consulted only when a relation has opted in via
 * {@see RelationInterface::linkageOnlyWhenLoaded()}.
 */
interface RelationshipLoadStateInterface
{
    /**
     * Returns true when the linkage data for `$relation` on `$model` is already
     * loaded in memory and can be read without a storage round-trip; false when
     * reading it would trigger a (lazy) load.
     *
     * Implementations must not themselves trigger a load while answering. The
     * `$relation` carries the cardinality ({@see RelationInterface::isToMany()})
     * and the backing column ({@see \haddowg\JsonApi\Resource\Field\FieldInterface::column()})
     * the adapter needs to map the JSON:API relationship back to its storage
     * association.
     */
    public function isRelationshipLoaded(mixed $model, RelationInterface $relation): bool;
}
