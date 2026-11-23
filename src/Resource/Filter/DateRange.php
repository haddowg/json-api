<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A {@see Range} over a **date/time** column: each present bound is coerced from an
 * RFC 3339 date-time to a `\DateTimeImmutable` (and the column value likewise) so the
 * `min <= value <= max` comparison is temporal rather than lexical — `published`
 * `before`/`after` in one key. Either bound may be omitted for an open-ended
 * range, and an entirely absent value is a no-op. The built, readonly value object;
 * authors declare one with {@see make()}, which returns a mutable {@see DateRangeBuilder}.
 *
 * Wire shape is the same nested `?filter[<key>][min]=…&filter[<key>][max]=…` as
 * its parent. The bounds are RFC 3339 date-time strings with an offset, compared as
 * instants, or full dates compared as calendar days when {@see $calendarDays} is set,
 * which a resource does itself for a range over a `Date` field's column. A handler's existing
 * `instanceof Range` arm dispatches it unchanged — only the preset deserializer
 * (on the builder) differs.
 */
final readonly class DateRange extends \haddowg\JsonApi\Resource\Filter\Range
{
    /**
     * An RFC 3339 date-time with an offset, the `date-time` each bound is documented as:
     * `1997-05-21T12:30:00Z`, `1997-05-21T12:30:00.5+01:00`. Each present bound is
     * validated against this so a malformed bound (`filter[<key>][min]=banana`, a bare
     * `1997-05-21` or a time with no offset) is a clean `400` rather than a silent
     * non-match.
     *
     * A regex cannot reject a calendar-invalid date (`2026-02-30T00:00:00Z` matches the
     * shape), so the framework adapter's pre-provider filter-value validation asks
     * {@see invalidBound()} first, which checks the shape and the day together and answers
     * one message for either as the same clean `400`. When that validation is
     * absent a handler skips such a bound identically on every provider (see
     * {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler::range()}).
     */
    public const string DATE_TIME_PATTERN = '^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$';

    /**
     * An RFC 3339 full date, the `date` each bound of a {@see $calendarDays} range is
     * documented as.
     */
    public const string DATE_PATTERN = '^\d{4}-\d{2}-\d{2}$';

    public const string DATE_TIME_DESCRIPTION = 'Matches values within the given inclusive date-time range.';

    public const string DATE_DESCRIPTION = 'Matches values within the given inclusive date range.';

    /**
     * @param \Closure(mixed): mixed|null                                   $deserialize
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints
     * @param bool                                                           $calendarDays whether each bound is a full date compared as a calendar day, rather than a date-time compared as an instant
     */
    public function __construct(
        string $key,
        string $column,
        ?\Closure $deserialize = null,
        array $constraints = [],
        ?string $description = null,
        bool $hasExample = false,
        mixed $example = null,
        bool $deprecated = false,
        bool $presetDescription = false,
        public bool $calendarDays = false,
        ?string $through = null,
        ?\haddowg\JsonApi\Resource\Through\RelationPath $resolvedThrough = null,
    ) {
        parent::__construct($key, $column, $deserialize, $constraints, $description, $hasExample, $example, $deprecated, $presetDescription, $through, $resolvedThrough);
    }

    public static function make(string $key, ?string $column = null): DateRangeBuilder
    {
        return DateRangeBuilder::make($key, $column);
    }

    /**
     * The message refusing a bound that is not the date this range takes: a full date when
     * it compares calendar days, else an RFC 3339 date-time with an offset. Its example is
     * the declared one for `$bound`: the example itself when it is a string, or its `min`
     * or `max` member when it is an object of bounds.
     *
     * @param ?string $bound `min` or `max`
     */
    public function dateMessage(?string $bound = null): \haddowg\JsonApi\Resource\DateMessage
    {
        $declared = $this->hasExample() ? $this->getExample() : null;
        if (\is_array($declared)) {
            $declared = $declared[$bound ?? 'min'] ?? $declared[$bound === 'max' ? 'min' : 'max'] ?? null;
        }
        $example = \is_string($declared) && $declared !== '' ? $declared : null;

        return $this->calendarDays
            ? \haddowg\JsonApi\Resource\DateMessage::date($example)
            : \haddowg\JsonApi\Resource\DateMessage::dateTime($example);
    }

    /**
     * The message refusing a present bound, `null` when it is the date this range takes. A
     * bound is read strictly, in the shape it is documented as and only when the day
     * exists, so `banana`, `1997-05-21` for a date-time and `2026-02-30` are each refused.
     * An adapter validating a request's bounds asks this before any other rule, so a bad
     * date is reported once, with this message, naming the parameter that carried it.
     *
     * @param string $bound     `min` or `max`
     * @param string $parameter the parameter the bound was sent as, such as `filter[published][min]`
     */
    public function invalidBound(mixed $value, string $bound, string $parameter): ?string
    {
        if (\is_string($value)) {
            try {
                $this->calendarDays
                    ? \haddowg\JsonApi\Resource\DateTimeParser::parseDate($value)
                    : \haddowg\JsonApi\Resource\DateTimeParser::parseDateTime($value);

                return null;
            } catch (\InvalidArgumentException) {
            }
        }

        return $this->dateMessage($bound)->for($parameter);
    }

    /**
     * This range comparing calendar days, its preset date-time pattern and description
     * swapped for the date ones. {@see \haddowg\JsonApi\Resource\AbstractResource::allFilters()}
     * calls it for a range whose column one `Date` field backs.
     */
    public function withCalendarDays(): self
    {
        if ($this->calendarDays) {
            return $this;
        }

        $preset = $this->presetDescription && $this->description === self::DATE_TIME_DESCRIPTION;

        return $this->rebuilt([
            'constraints' => self::calendarDayConstraints($this->constraints),
            'description' => $preset ? self::DATE_DESCRIPTION : $this->description,
            'calendarDays' => true,
        ]);
    }

    /**
     * Resolved through relations, a range whose leaf is a `Date` field compares calendar
     * days, as one over its own type's `Date` column does.
     */
    public function withResolvedThrough(\haddowg\JsonApi\Resource\Through\RelationPath $path): static
    {
        $resolved = parent::withResolvedThrough($path);
        $leaf = $resolved->resolvedThrough?->leaf;
        $date = $leaf instanceof \haddowg\JsonApi\Resource\Field\Date
            || ($leaf instanceof \haddowg\JsonApi\Resource\Field\DateTime && $leaf->schemaFormat() === 'date');

        return $date ? $resolved->withCalendarDays() : $resolved;
    }

    /**
     * The constraints with the preset date-time pattern swapped for the full-date one.
     *
     * @internal
     *
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints
     *
     * @return list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    public static function calendarDayConstraints(array $constraints): array
    {
        return \array_map(
            static fn(\haddowg\JsonApi\Resource\Constraint\ConstraintInterface $constraint): \haddowg\JsonApi\Resource\Constraint\ConstraintInterface => $constraint instanceof \haddowg\JsonApi\Resource\Constraint\Pattern && $constraint->regex === self::DATE_TIME_PATTERN
                ? new \haddowg\JsonApi\Resource\Constraint\Pattern(self::DATE_PATTERN, $constraint->context, $constraint->documentsAs)
                : $constraint,
            $constraints,
        );
    }

    /**
     * Each bound is documented as a `date-time` string, or a `date` when the range
     * compares calendar days, rather than the raw `pattern` its constraint would
     * project, overriding the numeric parent's per-bound value schema.
     */
    protected function boundSchema(\haddowg\JsonApi\OpenApi\Schema $valueSchema): \haddowg\JsonApi\OpenApi\Schema
    {
        return \haddowg\JsonApi\OpenApi\Schema::ofType('string')->withFormat($this->calendarDays ? 'date' : 'date-time');
    }
}
