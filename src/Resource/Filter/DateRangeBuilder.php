<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see DateRange} value filter. A
 * {@see RangeBuilder} subclass whose {@see make()} presets the ISO-8601 temporal
 * deserializer/constraint; {@see build()} freezes the accumulated state into a
 * readonly {@see DateRange} value object.
 */
final class DateRangeBuilder extends RangeBuilder
{
    /**
     * An ISO-8601 date or date-time, with an optional time part and an optional
     * `Z`/`±hh:mm` zone — `1995-01-01`, `1995-01-01T00:00:00`, `1997-05-21T12:30:00Z`,
     * `1997-05-21T12:30:00+01:00`. Each present bound is validated against this so a
     * malformed bound (`filter[<key>][min]=banana`) is a clean `400` rather than a
     * silent non-match (the unparseable value is passed through unchanged by
     * {@see toDateTime()}).
     *
     * A regex cannot reject a calendar-invalid date (`1997-13-99` — month 13, day 99
     * — matches the shape), so this pattern is deliberately lenient on the calendar,
     * the same shape-level guard the numeric {@see Range} gets from its preset
     * `numeric()`. The *temporal* validity a regex can't express is enforced by the
     * framework adapter's pre-provider filter-value validation, which additionally
     * runs each present bound through {@see toDateTime()} and rejects a value that
     * does not coerce to `\DateTimeInterface` as the same clean `400` — so a
     * calendar-invalid bound never reaches the data layer as a raw string (where it
     * would compare divergently across providers). When that validation is absent a
     * handler skips such a bound identically on every provider (see
     * {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler::range()}).
     */
    private const ISO_8601 = '^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$';

    public static function make(string $key, ?string $column = null): static
    {
        return (new static($key, $column))
            ->deserializeUsing(static fn(mixed $value): mixed => self::toDateTime($value))
            ->pattern(self::ISO_8601)
            ->describedAs('Matches values within the given inclusive date-time range (min/max ISO-8601, either optional).');
    }

    public function build(): DateRange
    {
        return new DateRange($this->key, $this->column, $this->deserialize, $this->constraints, $this->description, $this->hasExample, $this->example);
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
