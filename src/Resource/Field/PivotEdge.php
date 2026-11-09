<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The model a computed {@see BelongsToMany} pivot field reads instead of a join row: the
 * parent whose relation is rendered and the related member one linkage identifier points
 * at. A host passes it to the field's `extractUsing()` / `computedUsing()` hook, so the
 * value can be derived per link with no association entity or pivot model behind it.
 */
final readonly class PivotEdge
{
    public function __construct(
        public mixed $parent,
        public mixed $related,
    ) {}
}
