<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\Keyset;

/**
 * One column of a resolved keyset: the store column the active sort (or the
 * appended primary key) maps to, plus its direction.
 *
 * The ordered list of these — the active-sort columns most-significant-first,
 * terminated by the non-null primary key — is the total order a cursor
 * (keyset) page walks. Every data layer resolves the same list from one
 * {@see KeysetResolver}, so a SQL push-down's forced `ORDER BY` and the
 * in-memory NULL=largest comparator cannot drift (ADR 0123).
 */
final readonly class KeysetColumn
{
    public function __construct(
        public string $column,
        public bool $descending,
    ) {}
}
