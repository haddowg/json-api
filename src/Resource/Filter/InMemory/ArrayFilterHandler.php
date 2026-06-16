<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter\InMemory;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\UnsupportedFilter;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereThrough;

/**
 * Reference {@see FilterHandlerInterface} operating on a
 * PHP `list<array|object>`. Used by the package's own integration tests and as a
 * worked example for adapter authors. **Not** a production query layer — it
 * filters in memory with no indexing; a real adapter pushes the predicate down
 * to its data store.
 *
 * @implements FilterHandlerInterface<list<mixed>>
 */
final class ArrayFilterHandler implements FilterHandlerInterface
{
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
        return match (true) {
            $filter instanceof Where => $this->where($filter, $value),
            $filter instanceof WhereIn => $this->whereIn($filter->column, $this->toList($value, $filter->delimiter), false),
            $filter instanceof WhereNotIn => $this->whereIn($filter->column, $this->toList($value, $filter->delimiter), true),
            $filter instanceof WhereIdIn => $this->whereIn($filter->column, $this->toList($value, $filter->delimiter), false),
            $filter instanceof WhereIdNotIn => $this->whereIn($filter->column, $this->toList($value, $filter->delimiter), true),
            $filter instanceof WhereNull => static fn(mixed $row): bool => Accessor::get($row, $filter->column) === null,
            $filter instanceof WhereNotNull => static fn(mixed $row): bool => Accessor::get($row, $filter->column) !== null,
            $filter instanceof WhereThrough => $this->whereThrough($filter, $value),
            $filter instanceof WhereHas => fn(mixed $row): bool => $this->hasRelation($row, $filter->relationship),
            $filter instanceof WhereDoesntHave => fn(mixed $row): bool => !$this->hasRelation($row, $filter->relationship),
            default => throw new UnsupportedFilter($filter),
        };
    }

    /**
     * @return \Closure(mixed): bool
     */
    private function where(Where $filter, mixed $value): \Closure
    {
        $expected = $filter->deserialize !== null ? ($filter->deserialize)($value) : $value;

        return fn(mixed $row): bool => $this->compare(Accessor::get($row, $filter->column), $filter->operator, $expected);
    }

    /**
     * Traversal filter ({@see WhereThrough}): walk the dotted path, flat-mapping
     * across each relationship hop (a to-many hop fans out to every member), and
     * match if **any** reachable leaf value satisfies the operator — the in-memory
     * witness for a correlated EXISTS-ANY semi-join.
     *
     * @return \Closure(mixed): bool
     */
    private function whereThrough(WhereThrough $filter, mixed $value): \Closure
    {
        $expected = $filter->deserialize !== null ? ($filter->deserialize)($value) : $value;
        $segments = \explode('.', $filter->path);
        $leaf = fn(mixed $actual): bool => $this->compare($actual, $filter->operator, $expected);

        return fn(mixed $row): bool => $this->existsThrough($row, $segments, $leaf);
    }

    /**
     * Shared operator comparison, identical for a {@see Where} column and a
     * {@see WhereThrough} leaf so the two stay byte-for-byte equivalent.
     */
    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '=', '==' => $actual == $expected,
            '===' => $actual === $expected,
            '!=', '<>' => $actual != $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            // Contains, case-insensitive for ASCII — the semantics a SQL
            // `LIKE '%…%'` gives on common backends (SQLite folds ASCII
            // only; anything beyond is platform-defined), so database
            // adapters can match this reference behaviour.
            'like' => \is_string($actual) && \is_string($expected) && \stripos($actual, $expected) !== false,
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
    private function existsThrough(mixed $row, array $segments, ?\Closure $leaf): bool
    {
        $segment = $segments[0];
        $rest = \array_slice($segments, 1);
        $isLeafHop = $rest === [];

        foreach ($this->fanOut(Accessor::get($row, $segment)) as $next) {
            if ($isLeafHop) {
                if ($leaf === null || $leaf($next)) {
                    return true;
                }

                continue;
            }

            if ($this->existsThrough($next, $rest, $leaf)) {
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
     * @param list<mixed> $values
     * @return \Closure(mixed): bool
     */
    private function whereIn(string $column, array $values, bool $negate): \Closure
    {
        return static function (mixed $row) use ($column, $values, $negate): bool {
            $actual = Accessor::get($row, $column);
            $contained = \in_array($actual, $values, false);

            return $negate ? !$contained : $contained;
        };
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
}
