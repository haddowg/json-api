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

        return function (mixed $row) use ($filter, $expected): bool {
            $actual = Accessor::get($row, $filter->column);

            return match ($filter->operator) {
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
        };
    }

    /**
     * Existence test for a relationship: a non-empty related collection/array or
     * a non-null to-one value. The request `filter[...]` value is irrelevant —
     * presence alone decides the match (a {@see WhereHas} keeps such rows; a
     * {@see WhereDoesntHave} keeps the complement).
     */
    private function hasRelation(mixed $row, string $relationship): bool
    {
        $related = Accessor::get($row, $relationship);

        if ($related === null) {
            return false;
        }

        if (\is_array($related)) {
            return $related !== [];
        }

        if ($related instanceof \Countable) {
            return \count($related) > 0;
        }

        if ($related instanceof \Traversable) {
            foreach ($related as $_) {
                return true;
            }

            return false;
        }

        return true;
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
