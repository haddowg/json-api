<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A filter value read as the date of the field the filter compares, which a data layer
 * binds as any `\DateTimeInterface` and the reference in-memory handler compares by
 * {@see $kind}: a `date` by its calendar day, a `time` by its time of day and a
 * `date-time` as an instant, as a database compares a date, time or date-time column.
 * {@see DateFilterValue::withDateOperands()} gives a filter over a date field the
 * deserializer that makes one.
 */
final class DateOperand extends \DateTimeImmutable
{
    /**
     * @param 'date-time'|'date'|'time' $kind
     */
    public function __construct(
        string $datetime,
        ?\DateTimeZone $timezone,
        public readonly string $kind,
    ) {
        parent::__construct($datetime, $timezone);
    }

    /**
     * `$at`, its instant and offset kept, as an operand of `$kind`.
     *
     * @param 'date-time'|'date'|'time' $kind
     */
    public static function of(\DateTimeInterface $at, string $kind): self
    {
        return new self($at->format('Y-m-d\\TH:i:s.uP'), null, $kind);
    }

    /**
     * `$at` as this kind of date compares it, in a string that orders as the value does:
     * its day, its time of day, or its instant in UTC, each in the zone it carries.
     */
    public function comparable(\DateTimeInterface $at): string
    {
        return match ($this->kind) {
            'date' => $at->format('Y-m-d'),
            'time' => $at->format('H:i:s.u'),
            default => \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u'),
        };
    }
}
