<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\Keyset;

use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;

/**
 * One column of a resolved keyset: the store column the active sort (or the
 * appended primary key) maps to, plus its direction.
 *
 * The ordered list of these — the active-sort columns most-significant-first,
 * terminated by the non-null primary key — is the total order a cursor
 * (keyset) page walks. Every data layer resolves the same list from one
 * {@see KeysetResolver}, so a SQL push-down's forced `ORDER BY` and the
 * in-memory NULL=largest comparator cannot drift.
 *
 * A level ordered by a custom sort carries it as `$sort`, with the sort key as
 * `$column` (the name its value is keyed under in a cursor token). There is no
 * store column to read: the value comes from {@see KeysetSortInterface::keysetValue()}
 * and the data layer's arm for the sort supplies the comparison.
 */
final readonly class KeysetColumn
{
    public function __construct(
        public string $column,
        public bool $descending,
        public ?KeysetSortInterface $sort = null,
    ) {}

    /**
     * This level in the opposite direction, as a backward page walks it.
     */
    public function flipped(): self
    {
        return new self($this->column, !$this->descending, $this->sort);
    }

    /**
     * The value `$row` carries on this level: the custom sort's
     * {@see KeysetSortInterface::keysetValue()}, or `$readColumn` for a store column.
     *
     * @param callable(object, string): mixed $readColumn
     */
    public function valueOf(object $row, callable $readColumn): mixed
    {
        return $this->sort !== null ? $this->sort->keysetValue($row) : $readColumn($row, $this->column);
    }
}
