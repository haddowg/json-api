<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches a column against none of a set of values (the negation of
 * {@see WhereIn}).
 */
final readonly class WhereNotIn implements Filter
{
    public function __construct(
        public string $key,
        public string $column,
        public ?string $delimiter = null,
        public bool $singular = false,
    ) {}

    public static function make(string $key, ?string $column = null): self
    {
        return new self($key, $column ?? $key);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function delimiter(string $delimiter): self
    {
        return new self($this->key, $this->column, $delimiter, $this->singular);
    }

    public function singular(): self
    {
        return new self($this->key, $this->column, $this->delimiter, true);
    }
}
