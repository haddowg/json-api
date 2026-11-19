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
     * shape), so the *temporal* validity a regex can't express is enforced by the
     * framework adapter's pre-provider filter-value validation, which additionally runs
     * each present bound through the deserializer and rejects a value that does not
     * coerce to `\DateTimeInterface` as the same clean `400`. When that validation is
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
