<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Data;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;

/**
 * The in-memory analog of a pushed-down `COUNT(…)`: supplies the cardinality core
 * renders as `meta.total` on a relationship object when the request names a
 * {@see RelationInterface::isCountable() countable} to-many relation in
 * `?withCount`.
 *
 * Core never computes a count itself ({@see RelationshipCountInterface}); a
 * data-layer adapter supplies it through this seam. Here the related objects are
 * held directly on the parent (the catalog's object-graph seed, read by the
 * default relation reader), so the count is just the size of that collection — read
 * off the parent by the relation's backing property (`column()`, falling back to
 * the relation `name()`), the same property the handler's relationship apply uses.
 * A real adapter would instead push a `COUNT(…)` down (and batch it across the
 * fetched page of parents to avoid an N+1).
 */
final class InMemoryRelationshipCount implements RelationshipCountInterface
{
    public function countRelationship(mixed $model, RelationInterface $relation): ?int
    {
        if (!\is_object($model) || !$relation->isToMany()) {
            return null;
        }

        $related = Accessor::get($model, $relation->column() ?? $relation->name());

        if (\is_array($related)) {
            return \count($related);
        }

        if ($related instanceof \Countable) {
            return \count($related);
        }

        if ($related instanceof \Traversable) {
            return \iterator_count($related);
        }

        return null;
    }
}
