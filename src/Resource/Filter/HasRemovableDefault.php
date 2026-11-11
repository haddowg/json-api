<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A {@see HasDefaultValue} filter that can hand back a copy of itself with no default,
 * so a relation declaring `withoutFilterDefaults()` keeps the filter as a requested
 * filter while the related type's default stops applying. Every built-in value filter
 * implements it.
 *
 * A separate interface rather than a method on {@see HasDefaultValue}, so a host's own
 * defaulting filter keeps compiling. A custom filter whose default a relation drops
 * must implement it: {@see RelationFilters::relatedFilters()} and the warm-up
 * validator refuse one that does not.
 */
interface HasRemovableDefault extends HasDefaultValue
{
    /**
     * The same filter with no default declared, every other member unchanged, so a
     * handler dispatches it exactly as it does the original.
     */
    public function withoutDefault(): static;
}
