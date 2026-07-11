<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A {@see Range} over a **date/time** column: each present bound is coerced
 * ISO-8601 → `\DateTimeImmutable` (and the column value likewise) so the
 * `min <= value <= max` comparison is temporal rather than lexical — `published`
 * `before`/`after` in one key. Either bound may be omitted for an open-ended
 * range, and an entirely absent value is a no-op. The built, readonly value object;
 * authors declare one with {@see make()}, which returns a mutable {@see DateRangeBuilder}.
 *
 * Wire shape is the same nested `?filter[<key>][min]=…&filter[<key>][max]=…` as
 * its parent (the bounds are ISO-8601 date-time strings). A handler's existing
 * `instanceof Range` arm dispatches it unchanged — only the preset deserializer
 * (on the builder) differs.
 */
final readonly class DateRange extends \haddowg\JsonApi\Resource\Filter\Range
{
    public static function make(string $key, ?string $column = null): DateRangeBuilder
    {
        return DateRangeBuilder::make($key, $column);
    }

    /**
     * A date-time range documents each bound as a `date-time` string rather than the
     * raw ISO-8601 `pattern` its constraint would project — the more meaningful OpenAPI
     * shape (spec §6), overriding the numeric parent's per-bound value schema.
     */
    protected function boundSchema(\haddowg\JsonApi\OpenApi\Schema $valueSchema): \haddowg\JsonApi\OpenApi\Schema
    {
        return \haddowg\JsonApi\OpenApi\Schema::ofType('string')->withFormat('date-time');
    }
}
