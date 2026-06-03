<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

/**
 * Sorts by a single column. The most common sort; schemas auto-derive one of
 * these for every field that declared `->sortable()`.
 */
final readonly class SortByField implements \haddowg\JsonApi\Resource\Sort\SortInterface
{
    public function __construct(
        public string $key,
        public string $column,
    ) {}

    public static function make(string $key, ?string $column = null): self
    {
        return new self($key, $column ?? $key);
    }

    public function key(): string
    {
        return $this->key;
    }
}
