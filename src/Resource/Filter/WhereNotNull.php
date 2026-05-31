<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows whose column is not null (the filter value is truthy).
 */
final readonly class WhereNotNull implements Filter
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
