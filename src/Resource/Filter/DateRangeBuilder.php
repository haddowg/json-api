<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\DateTimeParser;

/**
 * The mutable **filter builder** for a {@see DateRange} value filter. A
 * {@see RangeBuilder} subclass whose {@see make()} presets the RFC 3339 temporal
 * deserializer/constraint; {@see build()} freezes the accumulated state into a
 * readonly {@see DateRange} value object.
 */
final class DateRangeBuilder extends RangeBuilder
{
    /**
     * An RFC 3339 date-time with an offset, the `date-time` each bound is documented as:
     * `1997-05-21T12:30:00Z`, `1997-05-21T12:30:00.5+01:00`. Each present bound is
     * validated against this so a malformed bound (`filter[<key>][min]=banana`, a bare
     * `1997-05-21` or a time with no offset) is a clean `400` rather than a silent
     * non-match.
     *
     * A regex cannot reject a calendar-invalid date (`2026-02-30T00:00:00Z` matches the
     * shape), so the *temporal* validity a regex can't express is enforced by the
     * framework adapter's pre-provider filter-value validation, which additionally runs
     * each present bound through {@see toDateTime()} and rejects a value that does not
     * coerce to `\DateTimeInterface` as the same clean `400`. When that validation is
     * absent a handler skips such a bound identically on every provider (see
     * {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler::range()}).
     */
    private const RFC_3339_DATE_TIME = '^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$';

    public static function make(string $key, ?string $column = null): static
    {
        return (new static($key, $column))
            ->deserializeUsing(static fn(mixed $value): mixed => self::toDateTime($value))
            ->pattern(self::RFC_3339_DATE_TIME)
            ->presetDescription('Matches values within the given inclusive date-time range.');
    }

    public function build(): DateRange
    {
        return new DateRange($this->key, $this->column, $this->deserialize, $this->constraints, $this->description, $this->hasExample, $this->example, $this->deprecated, $this->presetDescription);
    }

    /**
     * Coerces a date string to a `\DateTimeImmutable`; a value already a
     * `\DateTimeInterface` is returned as-is, and an unparseable, impossible
     * (`2026-02-30T00:00:00Z`) or blank value is returned unchanged (so a
     * constraint-rejected value still reaches the validator as-sent rather than throwing
     * here). It reads leniently because a handler runs the column value through it too,
     * and a stored value need not be RFC 3339; the pattern is what holds a bound to it.
     */
    private static function toDateTime(mixed $value): mixed
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        return DateTimeParser::tryParse($value) ?? $value;
    }
}
