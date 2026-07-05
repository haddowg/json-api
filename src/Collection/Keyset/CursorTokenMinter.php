<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\Keyset;

use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Pagination\CursorBoundary;
use haddowg\JsonApi\Pagination\CursorCodec;
use haddowg\JsonApi\Pagination\CursorWindow;

/**
 * Mints the boundary cursor tokens for a sliced keyset page and assembles the
 * {@see CursorCollectionResult}, shared by every data layer so the encoded
 * shape is identical (ADR 0123). The data layer supplies the row →
 * keyset-value reader (an ORM via its column metadata, in-memory via core's
 * `Accessor`); this class owns the JSON-safe coercion, the forward/backward
 * has-flag rules, and the {@see CursorCodec} encoding.
 *
 * `next` (the last row's cursor) is emitted only when a further forward page
 * follows; `prev` (the first row's cursor) only when not the first page.
 */
final class CursorTokenMinter
{
    public function __construct(private readonly CursorCodec $codec) {}

    /**
     * Assembles the {@see CursorCollectionResult} for the sliced `$rows` (already
     * in natural forward order) and the over-fetch `$hasSurplus` flag.
     *
     * The has-flag rules (the forward/backward edges, ADR 0123):
     *  - **forward** (`page[before]` absent): `hasMore` = the surplus row existed
     *    (a further forward page), `hasPrevious` = an `page[after]` was supplied
     *    (you are not on the first page);
     *  - **backward** (`page[before]` present, wins over `page[after]`):
     *    `hasPrevious` = the surplus existed (a further previous page), `hasMore`
     *    is always true (you navigated here from a later page).
     *
     * @param list<KeysetColumn>                    $columns   the keyset columns the values are read for
     * @param list<object>                          $rows      the page rows in natural forward order
     * @param callable(object,string):(scalar|null) $readValue reads a row's keyset value for a column, already JSON-safe-coercible
     *
     * @return CursorCollectionResult<object>
     */
    public function mint(CursorWindow $window, array $columns, array $rows, bool $hasSurplus, callable $readValue): CursorCollectionResult
    {
        $backward = $window->before !== null;

        $hasMore = $backward ? true : $hasSurplus;
        $hasPrevious = $backward ? $hasSurplus : ($window->after !== null);

        $first = $rows[0] ?? null;
        $last = $rows !== [] ? $rows[\array_key_last($rows)] : null;

        $cursorBefore = $first !== null ? $this->encode($columns, $first, false, $readValue) : null;
        $cursorAfter = $last !== null ? $this->encode($columns, $last, true, $readValue) : null;

        return new CursorCollectionResult(
            $rows,
            cursorBefore: $cursorBefore,
            cursorAfter: $cursorAfter,
            hasPrevious: $hasPrevious,
            hasMore: $hasMore,
        );
    }

    /**
     * Encodes one boundary: read each keyset column's value off `$row`, coerce to
     * a JSON-safe scalar, and encode through the codec with the direction flag and
     * the per-column directions the token is minted under.
     *
     * The directions (keyed identically to the values — every keyset column incl.
     * the appended PK) pin the order the cursor was paged under, so the stale check
     * can reject a request that flips a column's direction (`?sort=name` →
     * `?sort=-name`) even when the column SET is unchanged (ADR 0123).
     *
     * @param list<KeysetColumn>                    $columns
     * @param callable(object,string):(scalar|null) $readValue
     */
    private function encode(array $columns, object $row, bool $pointsToNextItems, callable $readValue): string
    {
        $values = [];
        $descending = [];
        foreach ($columns as $column) {
            $values[$column->column] = $this->jsonSafe($readValue($row, $column->column));
            $descending[$column->column] = $column->descending;
        }

        return $this->codec->encode(new CursorBoundary($values, $pointsToNextItems, $descending));
    }

    private function jsonSafe(mixed $value): string|int|float|bool|null
    {
        return self::coerce($value);
    }

    /**
     * Coerces a keyset value to a JSON-safe scalar the codec accepts (and that
     * round-trips exactly back to the comparison): a {@see \DateTimeInterface} →
     * an RFC3339 string with microseconds, a {@see \Stringable} id (Uuid/Ulid) →
     * its string form, a scalar passes through, null stays null.
     *
     * Shared with {@see InMemoryKeyset}, which coerces a ROW's value the same way
     * before comparing it to a boundary (which is already this wire form), so the
     * witness compares like-for-like with what it mints (ADR 0123).
     */
    public static function coerce(mixed $value): string|int|float|bool|null
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }
}
