<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A {@see Range} over a **date/time** column: each present bound is coerced
 * ISO-8601 → `\DateTimeImmutable` (and the column value likewise) so the
 * `min <= value <= max` comparison is temporal rather than lexical — `published`
 * `before`/`after` in one key. Either bound may be omitted for an open-ended
 * range, and an entirely absent value is a no-op.
 *
 * Wire shape is the same nested `?filter[<key>][min]=…&filter[<key>][max]=…` as
 * its parent (the bounds are ISO-8601 date-time strings). A handler's existing
 * `instanceof Range` arm dispatches it unchanged — only the preset deserializer
 * differs.
 */
final readonly class DateRange extends \haddowg\JsonApi\Resource\Filter\Range
{
    public static function make(string $key, ?string $column = null): static
    {
        return (new static($key, $column ?? $key))
            ->deserializeUsing(static fn(mixed $value): mixed => self::toDateTime($value))
            ->describedAs('Matches values within the given inclusive date-time range (min/max ISO-8601, either optional).');
    }

    /**
     * Coerces an ISO-8601 string to a `\DateTimeImmutable`; a value already a
     * `\DateTimeInterface` is returned as-is, and an unparseable/blank value is
     * returned unchanged (so a constraint-rejected value still reaches the
     * validator as-sent rather than throwing here).
     */
    private static function toDateTime(mixed $value): mixed
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (!\is_string($value) || $value === '') {
            return $value;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $value;
        }
    }
}
