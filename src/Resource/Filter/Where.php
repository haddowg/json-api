<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches a column against a value with a comparison operator (default `=`).
 */
final readonly class Where implements \haddowg\JsonApi\Resource\Filter\FilterInterface
{
    /**
     * @param \Closure(mixed): mixed|null $deserialize optional value transformer applied before comparison
     */
    public function __construct(
        public string $key,
        public string $column,
        public string $operator = '=',
        public ?\Closure $deserialize = null,
        public bool $singular = false,
    ) {}

    public static function make(string $key, ?string $column = null, string $operator = '='): self
    {
        return new self($key, $column ?? $key, $operator);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * Marks the filter as accepting a single value (not a comma list).
     */
    public function singular(): self
    {
        return new self($this->key, $this->column, $this->operator, $this->deserialize, true);
    }

    /**
     * @param \Closure(mixed): mixed $deserialize
     */
    public function deserializeUsing(\Closure $deserialize): self
    {
        return new self($this->key, $this->column, $this->operator, $deserialize, $this->singular);
    }

    /**
     * Coerces the incoming value to a boolean before comparison.
     */
    public function asBoolean(): self
    {
        return $this->deserializeUsing(static fn(mixed $value): bool => \filter_var($value, \FILTER_VALIDATE_BOOLEAN));
    }
}
