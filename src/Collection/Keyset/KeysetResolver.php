<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\Keyset;

use haddowg\JsonApi\Exception\CursorStale;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Pagination\CursorBoundary;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;

/**
 * Resolves the ordered keyset columns a cursor (keyset) page walks, shared by
 * every data layer so a SQL push-down's forced `ORDER BY`/keyset `WHERE` and
 * the in-memory NULL=largest comparator derive from ONE source and cannot
 * drift (ADR 0123).
 *
 * The active sort is resolved from the same inputs the plain (offset) sort
 * path reads — the requested `sort` values validated against the declared
 * vocabulary (an unknown key → 400 {@see SortParamUnrecognized}; sorting with
 * no declared sorts → 400 {@see SortingUnsupported}), falling back to the
 * resource's `defaultSort()` directives when none is requested — so the
 * cursor path's 400s are byte-identical to the offset path's. Each matched
 * directive must be a {@see SortByField} (a computed/multi-column sort has no
 * keyset translation → 500 {@see UnsupportedSort}, exactly as a plain sort
 * handler rejects it).
 *
 * The PRIMARY KEY is appended as the final keyset column (so the order is
 * total even when every active-sort column ties), unless the client already
 * sorts by it — then its own directive terminates the keyset. The PK's
 * direction follows the LAST active directive (a trailing `-title` makes the
 * tiebreak descending too, keeping the order monotone); a PK-only keyset (no
 * `sort`, no default) uses the supplied default PK direction.
 *
 * The id (PK) is non-null by definition, so the final level is a plain
 * `id >/< :v` tiebreak in the keyset WHERE the data layer builds from these
 * columns.
 */
final class KeysetResolver
{
    /**
     * Resolves the ordered keyset columns for the request's active sort,
     * terminating with the primary key `$pkColumn`.
     *
     * @param list<string>        $requestedSort       the request's `sort` fields (leading `-` preserved), `[]` when none requested
     * @param list<SortInterface> $sorts               the sort vocabulary declared for the type
     * @param list<SortDirective> $defaultSort         the resource's default sort directives, applied when no `sort` is requested
     * @param string              $pkColumn            the primary-key column (the type's id member)
     * @param bool                $pkDefaultDescending the PK direction for a PK-only keyset (no active sort), e.g. the resource's defaultSort-on-PK direction
     *
     * @return list<KeysetColumn> the active-sort columns most-significant-first, terminated by the PK
     *
     * @throws SortParamUnrecognized when a requested (or default) sort field is not declared
     * @throws SortingUnsupported    when sorting is requested but no sorts are declared
     * @throws UnsupportedSort       when a matched sort directive is not a {@see SortByField}
     */
    public function resolve(array $requestedSort, array $sorts, array $defaultSort, string $pkColumn, bool $pkDefaultDescending = false): array
    {
        $directives = $this->activeDirectives($requestedSort, $sorts, $defaultSort);

        $columns = [];
        $lastDescending = $pkDefaultDescending;
        $sawPk = false;
        foreach ($directives as $directive) {
            $sort = $directive->sort;
            if (!$sort instanceof SortByField) {
                throw new UnsupportedSort($sort);
            }

            $columns[] = new KeysetColumn($sort->column, $directive->descending);
            $lastDescending = $directive->descending;
            if ($sort->column === $pkColumn) {
                $sawPk = true;
            }
        }

        // Append the PK tiebreak unless the client already sorts by it (its own
        // directive then terminates the keyset and its direction wins). The
        // appended PK follows the last active directive's direction.
        if (!$sawPk) {
            $columns[] = new KeysetColumn($pkColumn, $lastDescending);
        }

        return $columns;
    }

    /**
     * The ordered keyset column NAMES (incl. the PK), the stable identity of the
     * keyset the cursor must have been minted under.
     *
     * @param list<KeysetColumn> $columns
     *
     * @return list<string>
     */
    public function columnNames(array $columns): array
    {
        return \array_map(static fn(KeysetColumn $column): string => $column->column, $columns);
    }

    /**
     * Asserts the decoded `$boundary` was minted under the SAME keyset columns,
     * in the same order AND under the same per-column directions — the staleness
     * check (the client changed `sort` while holding a cursor). The boundary's
     * value-keys (each active-sort column + the PK) must equal the resolved keyset
     * column names, in order, and each column's minted direction must match the
     * resolved one; either mismatch is a {@see CursorStale} on the offending
     * `page[…]` parameter.
     *
     * The direction comparison catches a same-columns flip (`?sort=name` →
     * `?sort=-name`) a column-set comparison alone cannot — the cursor would
     * otherwise be silently reused under the opposite order (ADR 0123). The
     * codec guarantees `$boundary->descending` is a clean string-keyed bool map
     * (a token lacking it → CursorMalformed upstream), so it compares directly.
     *
     * @param list<KeysetColumn> $columns   the resolved keyset columns
     * @param string             $parameter the cursor parameter being applied, e.g. `page[after]`
     *
     * @throws CursorStale when the boundary's columns or their directions do not match the resolved keyset
     */
    public function assertFresh(CursorBoundary $boundary, array $columns, string $parameter): void
    {
        if (\array_keys($boundary->values) !== $this->columnNames($columns)) {
            throw new CursorStale($parameter);
        }

        if ($boundary->descending !== $this->columnDirections($columns)) {
            throw new CursorStale($parameter);
        }
    }

    /**
     * The resolved keyset's per-column directions, keyed by column name (true =
     * descending) — the direction map a fresh cursor's `descending` must equal.
     *
     * @param list<KeysetColumn> $columns
     *
     * @return array<string, bool>
     */
    private function columnDirections(array $columns): array
    {
        $directions = [];
        foreach ($columns as $column) {
            $directions[$column->column] = $column->descending;
        }

        return $directions;
    }

    /**
     * The active sort directives for the request: the requested `sort` matched
     * against the declared vocabulary, falling back to the resource's default
     * sort when none is requested. Validation mirrors the plain (offset) sort
     * path exactly so the cursor path's 400s are byte-identical to the offset
     * path's.
     *
     * @param list<string>        $requestedSort
     * @param list<SortInterface> $sorts
     * @param list<SortDirective> $defaultSort
     *
     * @return list<SortDirective>
     *
     * @throws SortParamUnrecognized
     * @throws SortingUnsupported
     */
    private function activeDirectives(array $requestedSort, array $sorts, array $defaultSort): array
    {
        if ($requestedSort === []) {
            foreach ($defaultSort as $directive) {
                if ($this->sortFor($sorts, $directive->sort->key()) === null) {
                    throw new SortParamUnrecognized($directive->sort->key());
                }
            }

            return $defaultSort;
        }

        if ($sorts === []) {
            throw new SortingUnsupported();
        }

        $directives = [];
        foreach ($requestedSort as $field) {
            $descending = \str_starts_with($field, '-');
            $key = $descending ? \substr($field, 1) : $field;

            $sort = $this->sortFor($sorts, $key)
                ?? throw new SortParamUnrecognized($key);

            $directives[] = new SortDirective($sort, $descending);
        }

        return $directives;
    }

    /**
     * @param list<SortInterface> $sorts
     */
    private function sortFor(array $sorts, string $key): ?SortInterface
    {
        foreach ($sorts as $sort) {
            if ($sort->key() === $key) {
                return $sort;
            }
        }

        return null;
    }
}
