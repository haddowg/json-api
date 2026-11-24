<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter\InMemory;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\MorphSelection;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\ThroughPath;
use haddowg\JsonApi\Resource\Filter\UnsupportedFilter;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAll;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereSet;
use haddowg\JsonApi\Resource\Filter\WhereSetMatch;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Resource\Through\FiltersThrough;

/**
 * Reference {@see FilterHandlerInterface} operating on a
 * PHP `list<array|object>`. Used by the package's own integration tests and as a
 * worked example for adapter authors. **Not** a production query layer — it
 * filters in memory with no indexing; a real adapter pushes the predicate down
 * to its data store.
 *
 * A custom {@see FilterInterface} the built-ins do not recognise is delegated to a
 * registered {@see ArrayFilterArmInterface} (constructor-injected, first
 * {@see ArrayFilterArmInterface::supports()} match wins) before
 * {@see UnsupportedFilter} is raised — the in-memory half of the framework's
 * extensible-handler seam.
 *
 * A filter declared with `->through()` must be resolved first
 * ({@see \haddowg\JsonApi\Resource\Filter\ThroughPathResolver}). It keeps a row when
 * some row the path reaches matches the filter at its leaf, so a relation that holds
 * nothing never matches. {@see withRowAdmission()} narrows the rows each hop reaches to
 * the ones the reached type's scope admits, and {@see withMorphAdmission()} the targets a
 * {@see WhereMorph} matches to the ones its type's scope admits.
 *
 * @implements FilterHandlerInterface<list<mixed>>
 */
final class ArrayFilterHandler implements FilterHandlerInterface
{
    /**
     * @var list<ArrayFilterArmInterface>
     */
    private readonly array $arms;

    /**
     * @var (\Closure(string, list<mixed>, ?RelationInterface=): list<mixed>)|null
     */
    private ?\Closure $admit = null;

    /**
     * @var (\Closure(RelationInterface, string, list<mixed>): list<mixed>)|null
     */
    private ?\Closure $admitMorph = null;

    /**
     * @param iterable<ArrayFilterArmInterface> $arms author arms for custom filter types, consulted in order
     */
    public function __construct(iterable $arms = [])
    {
        $this->arms = \is_array($arms) ? \array_values($arms) : \iterator_to_array($arms, false);
    }

    /**
     * A copy that narrows the rows each relationship hop reaches before it matches them,
     * so a row the related type's scope hides is never matched through. `$admit`
     * receives the dotted storage path walked so far, such as `comments` and then
     * `comments.author`, and the values that hop reached, and returns the ones that
     * exist for the caller. It also sees the last segment of a path that ends on a
     * field, so it returns a segment that is no relationship unchanged. A filter declared
     * with `->through()` also passes the relation the hop walks, which decides the hop
     * when several relations share one storage member.
     *
     * @param \Closure(string, list<mixed>, ?RelationInterface=): list<mixed> $admit
     */
    public function withRowAdmission(\Closure $admit): self
    {
        $copy = clone $this;
        $copy->admit = $admit;

        return $copy;
    }

    /**
     * A copy that narrows the targets a {@see WhereMorph} matches to the ones the target
     * type's scope admits, so a row whose target the caller can't read never matches.
     * `$admit` receives the polymorphic relation, the type the request names and the
     * storage keys of that type's targets, and returns the keys that exist for the caller.
     *
     * @param \Closure(RelationInterface, string, list<mixed>): list<mixed> $admit
     */
    public function withMorphAdmission(\Closure $admit): self
    {
        $copy = clone $this;
        $copy->admitMorph = $admit;

        return $copy;
    }

    public function apply(FilterInterface $filter, mixed $query, mixed $value): mixed
    {
        if (!\is_array($query)) {
            $query = [];
        }

        /** @var list<mixed> $query */
        $predicate = $this->predicate($filter, $value);

        return \array_values(\array_filter($query, $predicate));
    }

    /**
     * @return \Closure(mixed): bool
     */
    private function predicate(\haddowg\JsonApi\Resource\Filter\FilterInterface $filter, mixed $value): \Closure
    {
        if ($filter instanceof FiltersThrough && $filter->throughRelations() !== null) {
            return $this->through($filter, $value);
        }

        return match (true) {
            $filter instanceof WhereAll => $this->whereAll($filter, $value),
            $filter instanceof WhereAny => $this->whereAny($filter, $value),
            $filter instanceof Where => $this->where($filter, $value),
            $filter instanceof WhereIn => $this->whereIn($filter->column, $this->members($filter, $value), false),
            $filter instanceof WhereNotIn => $this->whereIn($filter->column, $this->members($filter, $value), true),
            $filter instanceof WhereIdIn => $this->whereIn($filter->column, $this->members($filter, $value), false),
            $filter instanceof WhereIdNotIn => $this->whereIn($filter->column, $this->members($filter, $value), true),
            $filter instanceof WhereNull => static fn(mixed $row): bool => Accessor::get($row, $filter->column) === null,
            $filter instanceof WhereNotNull => static fn(mixed $row): bool => Accessor::get($row, $filter->column) !== null,
            $filter instanceof WhereThrough => $this->whereThrough($filter, $value),
            $filter instanceof WhereThroughIn => $this->whereThroughIn($filter, $value),
            $filter instanceof Range => $this->range($filter, $value),
            $filter instanceof WhereSet => $this->whereSet($filter, $value),
            $filter instanceof WhereMorph => $this->whereMorph($filter, $value),
            $filter instanceof WhereHas => fn(mixed $row): bool => $this->hasRelation($row, $filter->relationship),
            $filter instanceof WhereDoesntHave => fn(mixed $row): bool => !$this->hasRelation($row, $filter->relationship),
            default => $this->armPredicate($filter, $value),
        };
    }

    /**
     * A filter reaching through relations: some row the path reaches, admitted by its
     * type's scope, matches the filter at its leaf.
     *
     * @return \Closure(mixed): bool
     */
    private function through(FiltersThrough $filter, mixed $value): \Closure
    {
        // atLeaf() refuses a path that was never resolved.
        $leafFilter = $filter->atLeaf();
        $path = $filter->resolvedThrough();
        \assert($path !== null);
        $admit = $this->admit;
        $leaf = $this->predicate($leafFilter, $filter->leafValue($value));

        // An existence leaf asks whether the reached row's relation holds a row its type's
        // scope admits.
        $relation = $path->leafRelation();
        if ($admit !== null && $relation !== null && ($leafFilter instanceof WhereHas || $leafFilter instanceof WhereDoesntHave)) {
            $storage = $relation->column() ?? $relation->name();
            $walked = \implode('.', [...$path->storageSegments(), $storage]);
            $holds = fn(mixed $reached): bool => ($members = $this->fanOut(Accessor::get($reached, $storage))) !== [] && $admit($walked, $members, $relation) !== [];
            $leaf = $leafFilter instanceof WhereHas ? $holds : static fn(mixed $reached): bool => !$holds($reached);
        }

        return static function (mixed $row) use ($path, $leaf, $admit): bool {
            foreach ($path->reach($row, $admit) as $reached) {
                if ($leaf($reached)) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * The predicate for a custom {@see FilterInterface} from the first registered
     * arm that {@see ArrayFilterArmInterface::supports()} it; {@see UnsupportedFilter}
     * when none does (the same signal the built-in default arm gave).
     *
     * @return \Closure(mixed): bool
     */
    private function armPredicate(FilterInterface $filter, mixed $value): \Closure
    {
        foreach ($this->arms as $arm) {
            if ($arm->supports($filter)) {
                return $arm->predicate($filter, $value);
            }
        }

        throw new UnsupportedFilter($filter);
    }

    /**
     * @return \Closure(mixed): bool
     */
    private function where(Where $filter, mixed $value): \Closure
    {
        $expected = $filter->deserialize !== null ? ($filter->deserialize)($value) : $value;

        return fn(mixed $row): bool => $this->compare(self::column($row, $filter->column), $filter->operator, $expected);
    }

    /**
     * AND group ({@see WhereAll}): a row matches when it satisfies **every** child.
     * Each child predicate is built by re-entering {@see predicate()} — passing the
     * **group's** value down uniformly — so a fixed child ignores it, a fanning
     * child consumes it, and a nested group re-enters this same dispatch. An empty
     * group is the AND identity (matches every row).
     *
     * @return \Closure(mixed): bool
     */
    private function whereAll(WhereAll $filter, mixed $value): \Closure
    {
        $predicates = \array_map(fn(FilterInterface $child): \Closure => $this->predicate($child, $value), $filter->children);

        return static function (mixed $row) use ($predicates): bool {
            foreach ($predicates as $predicate) {
                if (!$predicate($row)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * OR group ({@see WhereAny}): a row matches when it satisfies **any** child.
     * Each child predicate re-enters {@see predicate()} with the **group's** value
     * (fanned uniformly across the children), so a value-carrying group is a
     * multi-column search and a fixed-child group a canned toggle; a nested group
     * re-enters this same dispatch. An empty group matches no row.
     *
     * @return \Closure(mixed): bool
     */
    private function whereAny(WhereAny $filter, mixed $value): \Closure
    {
        $predicates = \array_map(fn(FilterInterface $child): \Closure => $this->predicate($child, $value), $filter->children);

        return static function (mixed $row) use ($predicates): bool {
            foreach ($predicates as $predicate) {
                if ($predicate($row)) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * Traversal filter ({@see WhereThrough}): walk the dotted path, flat-mapping
     * across each relationship hop (a to-many hop fans out to every member), and
     * match if **any** reachable leaf value satisfies the operator — the in-memory
     * witness for a correlated EXISTS-ANY semi-join. A path resolved to a
     * relationship leaf compares each related row's storage key with the decoded id.
     *
     * @return \Closure(mixed): bool
     */
    private function whereThrough(WhereThrough $filter, mixed $value): \Closure
    {
        $expected = $filter->storageValue($value);
        $read = $this->leafReader($filter->resolved);
        $leaf = fn(mixed $actual): bool => $this->compare($read($actual), $filter->operator, $expected);

        return fn(mixed $row): bool => $this->existsThrough($row, ThroughPath::split($filter->path), $leaf);
    }

    /**
     * Traversal set filter ({@see WhereThroughIn}): {@see whereThrough()}'s walk with a
     * leaf that matches any of the list's values, so an empty list matches nothing.
     *
     * @return \Closure(mixed): bool
     */
    private function whereThroughIn(WhereThroughIn $filter, mixed $value): \Closure
    {
        $values = $filter->storageValues($value);
        $read = $this->leafReader($filter->resolved);
        $leaf = static fn(mixed $actual): bool => \in_array($read($actual), $values, false);

        return fn(mixed $row): bool => $values !== [] && $this->existsThrough($row, ThroughPath::split($filter->path), $leaf);
    }

    /**
     * What a leaf compares of the value the walk reached: a related row's storage key
     * when the path ends on a relationship, the value itself when it ends on a field.
     *
     * @return \Closure(mixed): mixed
     */
    private function leafReader(?ThroughPath $path): \Closure
    {
        $column = $path?->relatedIdColumn();

        return $column === null
            ? static fn(mixed $actual): mixed => $actual instanceof \BackedEnum ? $actual->value : $actual
            : static fn(mixed $related): mixed => Accessor::get($related, $column);
    }

    /**
     * Inclusive range filter ({@see Range}): the structured value carries an
     * optional `min` and/or `max` bound (the nested
     * `filter[<key>][min]`/`[max]` query, which Symfony parses into an array).
     * Each **present, non-blank** bound and the column value are coerced through
     * the filter's deserializer (numeric for {@see Range}, date string →
     * `\DateTimeImmutable` for {@see DateRange}, reduced to its calendar day when the
     * range compares days), then tested `min <= v <= max`
     * over whichever bounds are present — `min` alone is a `>=`, `max` alone a
     * `<=`. A value that is not an array, or that supplies neither bound, is a
     * no-op (keeps every row).
     *
     * A {@see DateRange} bound whose value is shape-valid RFC 3339 but
     * calendar-invalid (`2026-02-30T00:00:00Z` passes the shape `Pattern` but not
     * `\DateTimeImmutable`) does not coerce to a `\DateTimeInterface`; the
     * framework adapter's pre-provider validation rejects it as a clean `400`, but
     * when that validation is absent this handler **skips** such a bound (treats it
     * as open/absent) rather than comparing a `\DateTimeImmutable` column against a
     * raw string — which PHP would silently coerce to a lexical compare, diverging
     * from a database adapter. {@see bound()} applies this guard, so both bounds and
     * both providers degrade identically.
     *
     * A row whose **column value is `null`** never falls within a present bound:
     * the raw value is read *before* the deserializer and excluded, mirroring SQL
     * three-valued logic (a NULL column against a present bound is UNKNOWN, so the
     * row is excluded) rather than PHP's coercion of `null` toward `0`. The check
     * is repeated *after* the deserializer so a custom mapping that produces `null`
     * from a non-null raw is excluded too, never coerced through a bound. See ADR 0045.
     *
     * @return \Closure(mixed): bool
     */
    private function range(Range $filter, mixed $value): \Closure
    {
        $bounds = \is_array($value) ? $value : [];
        $min = $this->bound($filter, $bounds, 'min');
        $max = $this->bound($filter, $bounds, 'max');

        return function (mixed $row) use ($filter, $min, $max): bool {
            if ($min === null && $max === null) {
                return true;
            }

            // Read the raw column BEFORE the deserializer so a mapping that
            // coerces null (e.g. null->0) cannot smuggle a NULL row into a
            // present bound: a NULL column never falls within a bound (SQL
            // UNKNOWN), so it is excluded on every provider.
            $actual = self::column($row, $filter->column);
            if ($actual === null) {
                return false;
            }

            // Re-check after the deserializer: a custom mapping that turns a
            // non-null raw into null (e.g. a '' sentinel -> null) must not
            // slip a NULL through a max-only bound (null <= max coerces to
            // true in PHP). Both operands of every ordered test stay non-null,
            // matching compare()'s guard.
            $actual = $filter->deserialize !== null ? ($filter->deserialize)($actual) : $actual;
            if ($actual === null) {
                return false;
            }
            $actual = self::calendarDayOf($filter, $actual);

            if ($min !== null && !($actual >= $min)) {
                return false;
            }

            return $max === null || $actual <= $max;
        };
    }

    /**
     * Extracts and coerces one range bound: returns the deserialized bound value,
     * or `null` when the bound is absent or blank (so an open-ended range works).
     *
     * For a {@see DateRange} a coercion that did not yield a `\DateTimeInterface`
     * (a shape-valid but impossible string such as `2026-02-30T00:00:00Z`) is also
     * treated as absent, so the comparison never crosses a `\DateTimeImmutable`
     * column against a raw string — see {@see range()}.
     *
     * @param array<array-key, mixed> $bounds
     */
    private function bound(Range $filter, array $bounds, string $key): mixed
    {
        if (!\array_key_exists($key, $bounds)) {
            return null;
        }

        $value = $bounds[$key];
        if ($value === null || $value === '') {
            return null;
        }

        $value = $filter->deserialize !== null ? ($filter->deserialize)($value) : $value;

        if ($filter instanceof DateRange && !$value instanceof \DateTimeInterface) {
            return null;
        }

        return self::calendarDayOf($filter, $value);
    }

    /**
     * For a {@see DateRange} comparing calendar days, the day a date names in its own
     * zone, as midnight UTC, so a bound and a stored date compare by day as a database's
     * date column does rather than by instant. Anything else is returned unchanged.
     */
    private static function calendarDayOf(Range $filter, mixed $value): mixed
    {
        if (!$filter instanceof DateRange || !$filter->calendarDays || !$value instanceof \DateTimeInterface) {
            return $value;
        }

        return new \DateTimeImmutable($value->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    /**
     * Shared operator comparison, identical for a {@see Where} column and a
     * {@see WhereThrough} leaf so the two stay byte-for-byte equivalent.
     *
     * An **ordered** comparison (`>`, `>=`, `<`, `<=`) against `null` never
     * matches: either operand being `null` yields false, mirroring SQL
     * three-valued logic (the predicate is UNKNOWN, excluding the row) rather
     * than PHP's silent coercion of `null` toward `0`/`''`. Loose/strict
     * equality (`=`/`==`/`!=`/`<>`/`===`) keeps native PHP semantics — `= null`
     * is expressed with the dedicated {@see WhereNull}/{@see WhereNotNull}
     * filters.
     */
    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        // A date filter value compares the stored date as a database compares the column:
        // by day, by time of day or as an instant.
        if ($expected instanceof \haddowg\JsonApi\Resource\Filter\DateOperand && $actual instanceof \DateTimeInterface) {
            [$actual, $expected] = [$expected->comparable($actual), $expected->comparable($expected)];
        }

        return match ($operator) {
            '=', '==' => $actual == $expected,
            '===' => $actual === $expected,
            '!=', '<>' => $actual != $expected,
            // An ordered comparison against `null` never matches: either operand
            // being `null` yields false, mirroring SQL three-valued logic (the
            // predicate is UNKNOWN, so the row is excluded) rather than PHP's
            // silent coercion of `null` toward `0`/`''`.
            '>' => $actual !== null && $expected !== null && $actual > $expected,
            '>=' => $actual !== null && $expected !== null && $actual >= $expected,
            '<' => $actual !== null && $expected !== null && $actual < $expected,
            '<=' => $actual !== null && $expected !== null && $actual <= $expected,
            // Contains, case-insensitive for ASCII — the semantics a SQL
            // `LIKE '%…%'` gives on common backends (SQLite folds ASCII
            // only; anything beyond is platform-defined), so database
            // adapters can match this reference behaviour.
            'like' => \is_string($actual) && \is_string($expected) && \stripos($actual, $expected) !== false,
            // Prefix / suffix, case-insensitive for ASCII (same fold as `like`)
            // — the semantics a SQL `LIKE '…%'` / `LIKE '%…'` gives, so database
            // adapters can match this reference behaviour.
            'starts' => \is_string($actual) && \is_string($expected) && \stripos($actual, $expected) === 0,
            'ends' => \is_string($actual) && \is_string($expected) && \str_ends_with(\strtolower($actual), \strtolower($expected)),
            default => false,
        };
    }

    /**
     * Walks `$segments` from `$row`, fanning out across every to-many hop, and
     * returns whether **any** reached leaf value satisfies `$leaf`. A `null`
     * `$leaf` is the degenerate existence test ({@see WhereHas}): true when the
     * path reaches at least one present value — i.e. a non-empty related
     * collection or a non-null to-one across the whole chain.
     *
     * @param list<string>            $segments
     * @param (\Closure(mixed): bool)|null $leaf
     */
    private function existsThrough(mixed $row, array $segments, ?\Closure $leaf, string $walked = ''): bool
    {
        $segment = $segments[0];
        $rest = \array_slice($segments, 1);
        $isLeafHop = $rest === [];
        $path = $walked === '' ? $segment : $walked . '.' . $segment;

        $reached = $this->fanOut(Accessor::get($row, $segment));
        if ($this->admit !== null && $reached !== []) {
            $reached = ($this->admit)($path, $reached);
        }

        foreach ($reached as $next) {
            if ($isLeafHop) {
                if ($leaf === null || $leaf($next)) {
                    return true;
                }

                continue;
            }

            if ($this->existsThrough($next, $rest, $leaf, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Expands a hop value into the set of next-hop values: a to-many (a list array
     * or a `Traversable`) fans out to its members, while a present to-one — a
     * single object or an associative map (a `name => value` shape, *not* a list) —
     * is one value. `null` and an empty collection yield nothing. A list keyed
     * `0..n` reads as a to-many; an associative array reads as one related record,
     * the only honest distinction available without relationship metadata (a real
     * adapter resolves arity from its `ClassMetadata`).
     *
     * @return list<mixed>
     */
    private function fanOut(mixed $related): array
    {
        if ($related === null) {
            return [];
        }

        if (\is_array($related)) {
            return \array_is_list($related) ? $related : [$related];
        }

        if ($related instanceof \Traversable) {
            return \iterator_to_array($related, false);
        }

        return [$related];
    }

    /**
     * Existence test for a relationship: a non-empty related collection/array or
     * a non-null to-one value. The request `filter[...]` value is irrelevant —
     * presence alone decides the match (a {@see WhereHas} keeps such rows; a
     * {@see WhereDoesntHave} keeps the complement). Folded onto the shared
     * traversal as a degenerate length-1 path with no leaf predicate.
     */
    private function hasRelation(mixed $row, string $relationship): bool
    {
        return $this->existsThrough($row, [$relationship], null);
    }

    /**
     * @return \Closure(mixed): bool
     */
    private function whereSet(WhereSet $filter, mixed $value): \Closure
    {
        $column = $filter->column;

        return match ($filter->match($value)) {
            WhereSetMatch::Set => static fn(mixed $row): bool => Accessor::get($row, $column) !== null,
            WhereSetMatch::NotSet => static fn(mixed $row): bool => Accessor::get($row, $column) === null,
            WhereSetMatch::Either => static fn(mixed $row): bool => true,
            WhereSetMatch::Neither => static fn(mixed $row): bool => false,
        };
    }

    /**
     * A {@see WhereMorph} over a `MorphTo` compares the pair its declared
     * `identifiedByColumns()` read, and over a `MorphToMany` looks for a member among the
     * link rows its `identifiedByRows()` declare.
     *
     * @return \Closure(mixed): bool
     *
     * @throws \LogicException when the relation declares neither
     */
    private function whereMorph(WhereMorph $filter, mixed $value): \Closure
    {
        $selection = $filter->selection($value);
        $target = $filter->resolvedTarget();
        $relation = $target?->relation;
        $key = static fn(mixed $id): string => \is_scalar($id) ? (string) $id : '';
        // A stored id may be the target's storage key or its wire id.
        if ($selection->ids !== null && $target !== null) {
            $selection = new MorphSelection($selection->type, $target->storedForms($selection->type, $selection->ids));
        }
        $wanted = $selection->ids === null ? null : \array_map($key, $selection->ids);
        $admitted = $relation === null ? null : $this->morphAdmitted($relation, $selection, $key);
        $matches = static fn(string $type, mixed $id): bool => $type === $selection->type
            && ($wanted === null || \in_array($key($id), $wanted, true))
            && ($admitted === null || $admitted($id));

        if ($relation instanceof MorphTo && ($columns = $relation->identifierColumns()) !== null) {
            return static function (mixed $row) use ($columns, $matches): bool {
                $pair = $columns->pair($row);

                return $pair !== null && $matches($pair[0], $pair[1]);
            };
        }

        if ($relation instanceof MorphToMany && ($rows = $relation->linkRowFields()) !== null) {
            $storage = $relation->column() ?? $relation->name();

            return function (mixed $row) use ($rows, $storage, $matches): bool {
                foreach ($this->fanOut(Accessor::get($row, $storage)) as $link) {
                    $member = $rows->member($link);
                    if ($member !== null && $matches($member->type, $member->id)) {
                        return true;
                    }
                }

                return false;
            };
        }

        throw new \LogicException(\sprintf(
            'WhereMorph filter "%s" compares the relation "%s", which declares no fields to compare. Declare a MorphTo with identifiedByColumns(), or a MorphToMany with identifiedByRows().',
            $filter->key,
            $filter->relation,
        ));
    }

    /**
     * Whether the caller may read a target of the selected type, by its storage key, as
     * {@see withMorphAdmission()} says; `null` when nothing narrows the targets. Requested
     * ids are admitted in one call, and otherwise each key once as rows reach it.
     *
     * @param \Closure(mixed): string $key
     *
     * @return (\Closure(mixed): bool)|null
     */
    private function morphAdmitted(RelationInterface $relation, MorphSelection $selection, \Closure $key): ?\Closure
    {
        $admit = $this->admitMorph;
        if ($admit === null) {
            return null;
        }

        if ($selection->ids !== null) {
            $admitted = \array_map($key, $admit($relation, $selection->type, $selection->ids));

            return static fn(mixed $id): bool => \in_array($key($id), $admitted, true);
        }

        $known = [];

        return static function (mixed $id) use ($admit, $relation, $selection, $key, &$known): bool {
            return $known[$key($id)] ??= $admit($relation, $selection->type, [$id]) !== [];
        };
    }

    /**
     * @param list<mixed> $values
     * @return \Closure(mixed): bool
     */
    private function whereIn(string $column, array $values, bool $negate): \Closure
    {
        return function (mixed $row) use ($column, $values, $negate): bool {
            $actual = self::column($row, $column);
            $contained = false;
            foreach ($values as $value) {
                if ($value instanceof \haddowg\JsonApi\Resource\Filter\DateOperand ? $this->compare($actual, '=', $value) : $actual == $value) {
                    $contained = true;

                    break;
                }
            }

            return $negate ? !$contained : $contained;
        };
    }

    /**
     * The values a set filter compares against: the requested members, passed through a
     * `WhereIn` or `WhereNotIn` deserializer.
     *
     * @return list<mixed>
     */
    private function members(WhereIn|WhereNotIn|WhereIdIn|WhereIdNotIn $filter, mixed $value): array
    {
        $members = $filter->list?->members($value) ?? $this->toList($value, $filter->delimiter);

        return $filter instanceof WhereIn || $filter instanceof WhereNotIn ? $filter->comparedValues($members) : $members;
    }

    /**
     * @return list<mixed>
     */
    private function toList(mixed $value, ?string $delimiter): array
    {
        if (\is_array($value)) {
            return \array_values($value);
        }

        if (\is_string($value)) {
            $separator = $delimiter !== null && $delimiter !== '' ? $delimiter : ',';

            return \array_values(\array_map('\trim', \explode($separator, $value)));
        }

        return [$value];
    }

    /**
     * A column's value as a database compares it: a backed enum case, as an `enum()` field
     * hydrates, by its backing value.
     */
    private static function column(mixed $row, string $column): mixed
    {
        $value = Accessor::get($row, $column);

        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
