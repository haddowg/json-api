<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The model a {@see BelongsToMany} pivot field's `extractUsing()` / `computedUsing()` hook
 * receives instead of a join row: the parent whose relation is rendered, the related
 * member one linkage identifier points at, and the link's stored pivot values. The join
 * row itself is a Doctrine entity on one adapter and an Eloquent pivot model on another, so
 * a hook never sees it, and a computed value can be derived per link with no row at all.
 */
final readonly class PivotEdge
{
    /**
     * @param array<string, mixed> $pivot the link's stored pivot field values as the host read them, keyed by field name; empty when the link has no join row
     */
    public function __construct(
        public mixed $parent,
        public mixed $related,
        public array $pivot = [],
    ) {}
}
