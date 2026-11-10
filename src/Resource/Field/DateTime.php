<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\DateTimeParser;
use haddowg\JsonApi\Resource\Field\Internal\TimezoneName;

/**
 * An ISO-8601 date-time attribute (with timezone) — the built, readonly value
 * object the engine walks. Authors declare one with {@see make()}, which returns
 * a mutable {@see DateTimeBuilder}; the resource **builds** it into this value
 * object before use. Serializes a `\DateTimeInterface` to a string in
 * {@see $format}, first moving it into {@see $outputTimezone} when one is set;
 * hydrates a string back to a `\DateTimeImmutable`, moved into
 * {@see $useTimezone} (the builder's `inputTimezone()`) when one is set. Inside an
 * {@see Obj}, {@see OneOf} or structured {@see ArrayList} it hydrates to a string
 * instead ({@see hydrateNested()}), since a JSON column cannot hold the object.
 *
 * Because {@see $format} is configurable, the class alone does not say what shape
 * reaches the wire — {@see schemaFormat()} answers that, and is what the generated
 * schemas document.
 *
 * Non-final by design: {@see Date} and {@see Time} extend it.
 *
 * @phpstan-consistent-constructor
 */
readonly class DateTime extends AbstractFieldValue implements AcceptsDefaultOutputTimezoneInterface
{
    /**
     * The RFC 3339 shapes, as the `format` keyword names them. Checked in this order;
     * the three shapes are mutually exclusive, so at most one can match.
     */
    private const RFC_3339_RULES = ['date-time', 'date', 'time'];

    private const DATE_TIME_SHAPE = '/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

    private const TIME_SHAPE = '/^\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

    /**
     * An ISO 8601 date-time that names its own offset, so it reads as one instant
     * without guessing a zone.
     */
    private const STORED_INSTANT_SHAPE = '/^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?([Zz]|[+-]\d{2}(:?\d{2})?)$/';

    /**
     * What a nested child stores: its instant in UTC, to the microsecond.
     */
    private const NESTED_FORMAT = 'Y-m-d\\TH:i:s.uP';

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

    /**
     * @param ?string $useTimezone    the zone a hydrated value is moved into
     * @param ?string $outputTimezone the zone a serialized value is moved into before formatting
     *
     * @throws \InvalidArgumentException when either zone is not one PHP recognises
     */
    public function __construct(
        FieldState $state,
        protected string $format = \DateTimeInterface::ATOM,
        protected ?string $useTimezone = null,
        protected ?string $outputTimezone = null,
    ) {
        parent::__construct($state);

        if ($useTimezone !== null) {
            TimezoneName::assertValid($useTimezone, 'inputTimezone');
        }

        if ($outputTimezone !== null) {
            TimezoneName::assertValid($outputTimezone, 'outputTimezone');
        }
    }

    public static function make(string $name = ''): DateTimeBuilder
    {
        return new DateTimeBuilder($name);
    }

    /**
     * The zone serialized values are moved into before formatting, or `null` to
     * format each value in the zone it carries.
     */
    public function outputTimezone(): ?string
    {
        return $this->outputTimezone;
    }

    /**
     * Adopts the server's zone only when the field set none of its own, so a field's
     * `outputTimezone()` always wins.
     */
    public function withDefaultOutputTimezone(string $timezone): static
    {
        if ($this->outputTimezone !== null) {
            return $this;
        }

        return new static($this->state, $this->format, $this->useTimezone, $timezone);
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
        return $this->inOutputZone(self::probe(self::PROBES[0]))->format($this->format);
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
            $probe = $this->inOutputZone(self::probe($spec));
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

    /**
     * The probe as this field would render it: moved into {@see $outputTimezone}
     * when one is set, so a format that is only honest in that zone (a literal `Z`
     * under UTC) is judged by what the field really writes.
     */
    private function inOutputZone(\DateTimeImmutable $probe): \DateTimeImmutable
    {
        return $this->outputTimezone === null ? $probe : $probe->setTimezone(new \DateTimeZone($this->outputTimezone));
    }

    /**
     * A string from a closure is taken as already in wire form, whatever its shape, so
     * only a stored string is read back as an instant.
     */
    protected function serializeComputedValue(mixed $value): mixed
    {
        return \is_string($value) ? $value : $this->serializeValue($value);
    }

    protected function serializeValue(mixed $raw): mixed
    {
        $raw = $this->storedInstant($raw);
        if (!$raw instanceof \DateTimeInterface) {
            return $raw;
        }

        if ($this->outputTimezone !== null) {
            // A copy, so a mutable \DateTime on the domain object is never moved.
            $raw = \DateTimeImmutable::createFromInterface($raw)->setTimezone(new \DateTimeZone($this->outputTimezone));
        }

        return $raw->format($this->format);
    }

    /**
     * Reads a stored value back as an instant. Besides a `\DateTimeInterface`, that is
     * what a JSON column hands back for one: an ISO 8601 string carrying its offset, or
     * PHP's `{date, timezone_type, timezone}` dump of the object, rebuilt in its stored
     * zone. Anything else, including a string with no offset, is returned unchanged.
     *
     * A `DateTime` moves a string into PHP's default zone, the zone an ORM hands back a
     * naive date-time column in, so with no output zone a nested date renders as a
     * top-level one does. A `Date` or `Time` keeps the string's own offset, so its day
     * or time of day is never moved.
     */
    private function storedInstant(mixed $raw): mixed
    {
        if (\is_string($raw)) {
            $date = \preg_match(self::STORED_INSTANT_SHAPE, $raw) === 1 ? DateTimeParser::tryParse($raw) : null;
            if ($date === null) {
                return $raw;
            }

            return $this instanceof Date || $this instanceof Time
                ? $date
                : $date->setTimezone(new \DateTimeZone(\date_default_timezone_get()));
        }

        $dump = $raw instanceof \stdClass ? \get_object_vars($raw) : $raw;
        if (!\is_array($dump) || \count($dump) !== 3
            || !\is_string($dump['date'] ?? null) || !\is_int($dump['timezone_type'] ?? null) || !\is_string($dump['timezone'] ?? null)) {
            return $raw;
        }

        try {
            $zone = new \DateTimeZone($dump['timezone']);
        } catch (\Exception) {
            return $raw;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $dump['date'], $zone);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date === false || ($errors !== false && $errors['warning_count'] > 0) ? $raw : $date;
    }

    /**
     * Hydrates the value into a nested value: a key of an {@see Obj}, a {@see OneOf}
     * variant or a structured {@see ArrayList} element. The value is stored as the
     * string {@see deserializeNestedValue()} answers, so a JSON column keeps it as an
     * ISO 8601 string rather than PHP's dump of the object. A `fillUsing()` or
     * `deserializeUsing()` hook still owns the value.
     *
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public function hydrateNested(mixed $nested, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        $column = $this->state->column;
        if ($this->hydratesThroughHook() || $column === null) {
            return $this->hydrate($nested, $value, $data, $request, $creating);
        }

        return Accessor::set($nested, $column, $this->deserializeNestedValue($value));
    }

    /**
     * A non-string or empty value passes through unchanged (leniency shared with
     * the other attribute casts — type validity is a constraint/validation
     * concern, not the cast's). A non-empty string that {@see DateTimeParser}
     * rejects — calendar-garbage (`1997-13-99`), an impossible day PHP would roll
     * over (`2026-02-30`) or nonsense (`banana`) —
     * raises a typed {@see AttributeValueInvalid} (422 at
     * `/data/attributes/<name>`) rather than letting the raw parse `\Exception`
     * escape as an uncaught 500: the cast is the last gate before the value is
     * written onto the domain object.
     */
    protected function deserializeValue(mixed $value): mixed
    {
        $date = $this->parseWireValue($value);
        if ($date instanceof \DateTimeImmutable && $this->useTimezone !== null) {
            $date = $date->setTimezone(new \DateTimeZone($this->useTimezone));
        }

        return $date;
    }

    /**
     * The instant in UTC with microseconds and its offset, which
     * {@see serializeValue()} reads back as the same instant.
     */
    protected function deserializeNestedValue(mixed $value): mixed
    {
        $date = $this->deserializeValue($value);

        return $date instanceof \DateTimeImmutable
            ? $date->setTimezone(new \DateTimeZone('UTC'))->format(self::NESTED_FORMAT)
            : $date;
    }

    /**
     * The value parsed in the zone it names, or unchanged when it is not a non-empty
     * string.
     *
     * @throws AttributeValueInvalid when the string is not a valid date, date-time or time
     */
    protected function parseWireValue(mixed $value): mixed
    {
        if (!\is_string($value) || $value === '') {
            return $value;
        }

        try {
            return DateTimeParser::parse($value);
        } catch (\InvalidArgumentException $e) {
            throw new AttributeValueInvalid($this->name(), $e->getMessage());
        }
    }
}
