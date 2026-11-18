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
    private bool $calendarDays = false;

    public static function make(string $key, ?string $column = null): static
    {
        return (new static($key, $column))
            ->deserializeUsing(static fn(mixed $value): mixed => self::toDateTime($value))
            ->pattern(DateRange::DATE_TIME_PATTERN)
            ->presetDescription(DateRange::DATE_TIME_DESCRIPTION);
    }

    public function build(): DateRange
    {
        return new DateRange($this->key, $this->column, $this->deserialize, $this->constraints, $this->description, $this->hasExample, $this->example, $this->deprecated, $this->presetDescription, $this->calendarDays);
    }

    /**
     * Compares calendar days rather than instants: each bound is a full date such as
     * `2026-10-01`, documented as `format: date`. A resource sets this itself on a range
     * whose column one `Date` field backs; declare it for any other column holding dates,
     * such as one on a related type or a pivot.
     *
     * @return static
     */
    public function calendarDays(): static
    {
        $this->calendarDays = true;
        $this->constraints = DateRange::calendarDayConstraints($this->constraints);
        if ($this->presetDescription && $this->description === DateRange::DATE_TIME_DESCRIPTION) {
            $this->description = DateRange::DATE_DESCRIPTION;
        }

        return $this;
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
