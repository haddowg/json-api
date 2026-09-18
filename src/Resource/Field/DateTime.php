<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;

/**
 * An ISO-8601 date-time attribute (with timezone) — the built, readonly value
 * object the engine walks. Authors declare one with {@see make()}, which returns
 * a mutable {@see DateTimeBuilder}; the resource **builds** it into this value
 * object before use. Serializes a `\DateTimeInterface` to a string in
 * {@see $format}; hydrates a string back to a `\DateTimeImmutable`.
 *
 * Because {@see $format} is configurable, the class alone does not say what shape
 * reaches the wire — {@see schemaFormat()} answers that, and is what the generated
 * schemas document.
 *
 * Non-final by design: {@see Date} and {@see Time} extend it.
 */
readonly class DateTime extends AbstractFieldValue
{
    /**
     * The RFC 3339 shapes, as the `format` keyword names them. Checked in this order;
     * the three shapes are mutually exclusive, so at most one can match.
     */
    private const RFC_3339_RULES = ['date-time', 'date', 'time'];

    private const DATE_TIME_SHAPE = '/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

    private const TIME_SHAPE = '/^\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

    /**
     * The reference instants {@see schemaFormat()} renders. Between them they force
     * every value-dependent format character to give itself away: a single-digit
     * month / day / hour (so an unpadded `n` / `j` / `G` renders one character short),
     * an afternoon time (so a 12-hour `h` renders the wrong hour), a named zone inside
     * and outside DST (so `e` / `T` render a name instead of an offset), and UTC (so
     * `p` renders `Z`).
     *
     * @var list<array{string, string}>
     */
    private const PROBES = [
        ['2024-09-05 09:08:07.654321', 'Europe/Paris'],
        ['2024-12-25 23:59:58.000001', 'Europe/Paris'],
        ['2024-09-05 09:08:07.654321', 'UTC'],
    ];

    public function __construct(
        FieldState $state,
        protected string $format = \DateTimeInterface::ATOM,
        protected ?string $useTimezone = null,
    ) {
        parent::__construct($state);
    }

    public static function make(string $name): DateTimeBuilder
    {
        return new DateTimeBuilder($name);
    }

    /**
     * The JSON Schema / OpenAPI `format` keyword this field's serialized values
     * genuinely satisfy — `date-time`, `date`, `time`, or `null` when the configured
     * {@see $format} produces a shape no standard keyword describes. Read by the
     * OpenAPI projection and the body-validation schema compiler, both of which emit
     * the keyword only when it is true.
     *
     * The answer comes from the configured format itself rather than from the field's
     * class, so a {@see Date} handed a full date-time format reports what it actually
     * writes. `null` is the honest answer, never a fallback: all three keywords are
     * defined as RFC 3339 productions, so claiming one for a value that is not RFC 3339
     * misdirects every consumer that reads it.
     */
    public function schemaFormat(): ?string
    {
        foreach (self::RFC_3339_RULES as $rule) {
            if ($this->renders($rule)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * A representative serialized value, rendered from the first reference instant. The
     * OpenAPI projection uses it to describe the wire shape in prose when
     * {@see schemaFormat()} finds no standard keyword that names it.
     */
    public function sampleWireValue(): string
    {
        return self::probe(self::PROBES[0])->format($this->format);
    }

    /**
     * Whether the configured format renders the given RFC 3339 production for **every**
     * reference instant.
     *
     * Shape alone is not enough, so a rendered date-time or time is also parsed back and
     * compared to the instant it came from: that is what rejects a format hard-coding a
     * literal `Z` onto a value that is not UTC, or writing a 12-hour clock, both of which
     * are RFC-3339-shaped and mean the wrong moment.
     */
    private function renders(string $rule): bool
    {
        foreach (self::PROBES as $spec) {
            $probe = self::probe($spec);
            $rendered = $probe->format($this->format);

            if ($rule === 'date') {
                if ($rendered !== $probe->format('Y-m-d')) {
                    return false;
                }

                continue;
            }

            if (\preg_match($rule === 'time' ? self::TIME_SHAPE : self::DATE_TIME_SHAPE, $rendered) !== 1) {
                return false;
            }

            // A full-time is the time half of a date-time, so glue the probe's own date
            // back on and round-trip the whole thing through one comparison.
            $candidate = $rule === 'time' ? $probe->format('Y-m-d') . 'T' . $rendered : $rendered;

            try {
                if ((new \DateTimeImmutable($candidate))->getTimestamp() !== $probe->getTimestamp()) {
                    return false;
                }
            } catch (\Exception) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{string, string} $spec
     */
    private static function probe(array $spec): \DateTimeImmutable
    {
        return new \DateTimeImmutable($spec[0], new \DateTimeZone($spec[1]));
    }

    protected function serializeValue(mixed $raw): mixed
    {
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format($this->format);
        }

        return $raw;
    }

    /**
     * A non-string or empty value passes through unchanged (leniency shared with
     * the other attribute casts — type validity is a constraint/validation
     * concern, not the cast's). A non-empty string that {@see \DateTimeImmutable}
     * cannot parse — calendar-garbage (`1997-13-99`) or nonsense (`banana`) —
     * raises a typed {@see AttributeValueInvalid} (422 at
     * `/data/attributes/<name>`) rather than letting the raw parse `\Exception`
     * escape as an uncaught 500: the cast is the last gate before the value is
     * written onto the domain object.
     */
    protected function deserializeValue(mixed $value): mixed
    {
        if (!\is_string($value) || $value === '') {
            return $value;
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new AttributeValueInvalid($this->name(), $e->getMessage());
        }

        if ($this->useTimezone !== null) {
            $date = $date->setTimezone(new \DateTimeZone($this->useTimezone));
        }

        return $date;
    }
}
