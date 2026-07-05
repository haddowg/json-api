<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\Keyset;

use haddowg\JsonApi\Pagination\CursorBoundary;
use haddowg\JsonApi\Resource\Field\Accessor;

/**
 * The in-memory keyset execution — the **ground truth** a SQL push-down
 * must match byte-for-byte (ADR 0123).
 *
 * It sorts the array by the forced NULL=largest order (its OWN comparator, NOT
 * core's {@see \haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler}, whose
 * `<=>` orders NULL SMALLEST — the opposite), filters to the rows strictly after
 * the decoded boundary under the SAME order via the lexicographic AFTER
 * predicate, over-fetches `limit + 1`, slices, and (for a backward page) flips
 * the directions and reverses. Values are read off a row with core's
 * {@see Accessor}, so the witness reads exactly what the serializer renders.
 *
 * @see KeysetColumn the per-column (column, direction) the order is built from
 */
final class InMemoryKeyset
{
    /**
     * Sorts `$items` by the forced NULL=largest order for `$columns` (each
     * column compared null-ness first — non-null before null — then by value per
     * direction, terminated by the non-null PK so the order is total).
     *
     * @param list<object>       $items
     * @param list<KeysetColumn> $columns
     *
     * @return list<object>
     */
    public function sort(array $items, array $columns): array
    {
        \usort($items, fn(object $a, object $b): int => $this->compare($a, $b, $columns));

        return $items;
    }

    /**
     * The rows of `$sorted` strictly after `$boundary` under the order of
     * `$columns` — the lexicographic OR-of-levels AFTER predicate, null-aware.
     * `$sorted` must already be in the forced order (see {@see sort()}), so this
     * is a forward scan over a totally-ordered list.
     *
     * @param list<object>       $sorted  the items in forced NULL=largest order
     * @param list<KeysetColumn> $columns
     *
     * @return list<object>
     */
    public function after(array $sorted, CursorBoundary $boundary, array $columns): array
    {
        return \array_values(\array_filter(
            $sorted,
            fn(object $row): bool => $this->isAfter($row, $boundary, $columns),
        ));
    }

    /**
     * Whether `$row` is strictly after the `$boundary` under the order of
     * `$columns` — the keyset WHERE evaluated in PHP. The OR-of-levels: for each
     * level i, every higher-significance column equals the boundary (null-aware)
     * AND column i is strictly after the boundary on that column alone. The PK
     * level (boundary value non-null) is the plain `id >/< :v` tiebreak.
     *
     * @param list<KeysetColumn> $columns
     */
    public function isAfter(object $row, CursorBoundary $boundary, array $columns): bool
    {
        foreach ($columns as $level => $column) {
            // The equality prefix: every column more significant than this level
            // must equal the boundary (a null boundary value means IS NULL).
            $prefixHolds = true;
            for ($i = 0; $i < $level; $i++) {
                $higher = $columns[$i];
                if (!$this->equals(Accessor::get($row, $higher->column), $boundary->values[$higher->column] ?? null)) {
                    $prefixHolds = false;

                    break;
                }
            }

            if (!$prefixHolds) {
                continue;
            }

            if ($this->isAfterOnColumn(Accessor::get($row, $column->column), $boundary->values[$column->column] ?? null, $column->descending)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The total-order comparator for two rows under `$columns`, the in-memory
     * twin of a SQL push-down's forced `ORDER BY` — per column: non-null sorts
     * before null (NULL=largest), then by value in the column's direction; the
     * non-null PK column terminates the order so it is strict and total.
     *
     * @param list<KeysetColumn> $columns
     */
    private function compare(object $a, object $b, array $columns): int
    {
        foreach ($columns as $column) {
            $cmp = $this->compareValues(
                Accessor::get($a, $column->column),
                Accessor::get($b, $column->column),
                $column->descending,
            );
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    /**
     * Compares two column values under the forced NULL=largest order for a
     * direction: null is the maximal element (so a non-null is always before a
     * null in asc, after it in desc — the leading `(c IS NULL)` term of the
     * SQL `ORDER BY`); two non-nulls compare by value, flipped for desc.
     */
    private function compareValues(mixed $a, mixed $b, bool $descending): int
    {
        $aNull = $a === null;
        $bNull = $b === null;

        // NULL=largest: rank null AFTER non-null in ascending order. In
        // descending the whole order flips, so null ranks FIRST — exactly the
        // SQL `(c IS NULL) DESC` leading term.
        if ($aNull || $bNull) {
            if ($aNull && $bNull) {
                return 0;
            }
            $nullIsAfter = $aNull ? 1 : -1;

            return $descending ? -$nullIsAfter : $nullIsAfter;
        }

        $cmp = $this->spaceship($a, $b);

        return $descending ? -$cmp : $cmp;
    }

    /**
     * Whether `$value` is strictly after the boundary `$bound` on ONE column
     * alone, under the forced NULL=largest order for the direction — the four
     * AFTER cases:
     *   asc  + bound non-null:  value > bound OR value IS NULL  (nulls follow all non-nulls)
     *   asc  + bound null:      never                            (null is the maximal asc element)
     *   desc + bound non-null:  value < bound                    (nulls are first in desc, already before)
     *   desc + bound null:      value IS NOT NULL                (after a leading null come all non-nulls)
     */
    private function isAfterOnColumn(mixed $value, mixed $bound, bool $descending): bool
    {
        if (!$descending) {
            if ($bound === null) {
                return false;
            }

            return $value === null || $this->spaceship($value, $bound) > 0;
        }

        if ($bound === null) {
            return $value !== null;
        }

        return $value !== null && $this->spaceship($value, $bound) < 0;
    }

    /**
     * Null-aware equality for the keyset's EQ prefix: a null boundary value
     * matches a null row value (the IS-NULL branch — never `= null`, which would
     * wrongly drop the row). Two non-nulls compare by value.
     */
    private function equals(mixed $value, mixed $bound): bool
    {
        if ($value === null || $bound === null) {
            return $value === null && $bound === null;
        }

        return $this->spaceship($value, $bound) === 0;
    }

    /**
     * Compares two NON-null values under the wire form they round-trip as: a
     * row's {@see \DateTimeInterface} / {@see \Stringable} is coerced to the SAME
     * RFC3339-with-microseconds / string the boundary already carries (via
     * {@see CursorTokenMinter::coerce()}), so the witness compares like-for-like
     * with what it mints (a date row vs an ISO-8601 boundary string). Numerics
     * compare numerically so a wire string "10" does not sort lexically before
     * "9"; everything else compares lexically as the SQL comparison would.
     */
    private function spaceship(mixed $a, mixed $b): int
    {
        $a = CursorTokenMinter::coerce($a);
        $b = CursorTokenMinter::coerce($b);

        if (\is_numeric($a) && \is_numeric($b)) {
            return $a <=> $b;
        }

        return (string) $a <=> (string) $b;
    }
}
