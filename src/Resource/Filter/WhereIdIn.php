<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches the resource id against any value in a set. Equivalent to a
 * {@see WhereIn} targeting the id column; ships as a dedicated type because
 * id filtering is the most common case.
 */
final readonly class WhereIdIn implements Filter
{
    public function __construct(
        public string $key = 'id',
        public string $column = 'id',
        public ?string $delimiter = null,
    ) {}

    public static function make(string $key = 'id', string $column = 'id'): self
    {
        return new self($key, $column);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function delimiter(string $delimiter): self
    {
        return new self($this->key, $this->column, $delimiter);
    }
}
