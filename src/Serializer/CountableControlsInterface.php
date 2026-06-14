<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

/**
 * An opt-in capability a {@see SerializerInterface} MAY implement to declare
 * which of its relationships are **countable** — eligible for a `?withCount`
 * count exposed as `meta.total` on the relationship object. The resource document
 * reads it via `instanceof CountableControlsInterface` to validate the request's
 * `?withCount` up front: a name that is not in this set (not declared
 * {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::countable()}, or a
 * to-one relation) is rejected with
 * {@see \haddowg\JsonApi\Exception\RelationshipCountNotAllowed} (400) — mirroring
 * the root-scoped include allow-list check.
 *
 * Not part of {@see SerializerInterface}: a serializer that does not implement it
 * declares no countable relationships, so any `?withCount` against it is rejected
 * (the safe default — counting is opt-in). {@see \haddowg\JsonApi\Resource\AbstractResource}
 * implements it by deriving the set from its declared
 * {@see \haddowg\JsonApi\Resource\Field\RelationInterface::isCountable()} to-many
 * relations, so every Resource subclass satisfies the interface automatically.
 */
interface CountableControlsInterface
{
    /**
     * The relationship names that are countable for this resource — each declared
     * {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::countable()} and
     * to-many. A `?withCount` naming any other relationship is rejected (400).
     * Return an empty list to forbid all counting on this resource.
     *
     * @return list<string>
     */
    public function getCountableRelationships(mixed $object): array;
}
