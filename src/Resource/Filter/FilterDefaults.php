<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Folds declared filter defaults into a requested `filter[…]` map — the single
 * home of the presence semantics, so every adapter agrees on them rather than
 * re-deciding per data layer:
 *
 * - a requested key always wins, by **presence** (`array_key_exists`), so an
 *   explicit empty or null value still overrides the default;
 * - a default fills only its own absent key, in declaration order — when two
 *   declared filters share a key, the first wins (the same first-match rule
 *   adapters use to resolve a requested key to its declared filter).
 */
final class FilterDefaults
{
    /**
     * The requested map with every absent defaulted key filled in.
     *
     * @param array<string, mixed>  $requested the request's `filter[…]` map
     * @param list<FilterInterface> $declared  the resource's declared filters
     *
     * @return array<string, mixed>
     */
    public static function apply(array $requested, array $declared): array
    {
        foreach ($declared as $filter) {
            if (!$filter instanceof HasDefaultValue || !$filter->hasDefault()) {
                continue;
            }

            if (\array_key_exists($filter->key(), $requested)) {
                continue;
            }

            $requested[$filter->key()] = $filter->defaultValue();
        }

        return $requested;
    }
}
