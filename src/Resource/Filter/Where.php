<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches a column against a value with a comparison operator (default `=`).
 */
final readonly class Where implements \haddowg\JsonApi\Resource\Filter\FilterInterface, \haddowg\JsonApi\Resource\Filter\HasDefaultValue, \haddowg\JsonApi\Resource\Filter\SupportsSingular
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
        public mixed $default = null,
        public bool $hasDefault = false,
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
     * Marks this filter as yielding a zero-to-one result: when the client applies
     * it, the collection renders as a single resource object or `null`, not an
     * array. Use on a unique attribute (a slug, a UUID) — see {@see SupportsSingular}.
     */
    public function singular(): self
    {
        return new self($this->key, $this->column, $this->operator, $this->deserialize, true, $this->default, $this->hasDefault);
    }

    public function isSingular(): bool
    {
        return $this->singular;
    }

    /**
     * @param \Closure(mixed): mixed $deserialize
     */
    public function deserializeUsing(\Closure $deserialize): self
    {
        return new self($this->key, $this->column, $this->operator, $deserialize, $this->singular, $this->default, $this->hasDefault);
    }

    /**
     * Coerces the incoming value to a boolean before comparison.
     */
    public function asBoolean(): self
    {
        return $this->deserializeUsing(static fn(mixed $value): bool => \filter_var($value, \FILTER_VALIDATE_BOOLEAN));
    }

    /**
     * Declares the value to apply when the request omits this filter's key —
     * a requested value always wins ({@see HasDefaultValue}).
     */
    public function default(mixed $value): self
    {
        return new self($this->key, $this->column, $this->operator, $this->deserialize, $this->singular, $value, true);
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function defaultValue(): mixed
    {
        return $this->default;
    }
}
