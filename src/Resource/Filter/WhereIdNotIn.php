<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches the resource id against none of a set of values (the negation of
 * {@see WhereIdIn}).
 */
final readonly class WhereIdNotIn implements \haddowg\JsonApi\Resource\Filter\FilterInterface, \haddowg\JsonApi\Resource\Filter\HasDefaultValue
{
    use \haddowg\JsonApi\Resource\Filter\HasValueConstraints;

    /**
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints declared value constraints
     */
    public function __construct(
        public string $key = 'id',
        public string $column = 'id',
        public ?string $delimiter = null,
        public mixed $default = null,
        public bool $hasDefault = false,
        public array $constraints = [],
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
        return new self($this->key, $this->column, $delimiter, $this->default, $this->hasDefault, $this->constraints);
    }

    /**
     * Declares the value to apply when the request omits this filter's key —
     * a requested value always wins ({@see HasDefaultValue}). Shape it as the
     * request would carry it (an array, or a string the declared delimiter
     * splits).
     */
    public function default(mixed $value): self
    {
        return new self($this->key, $this->column, $this->delimiter, $value, true, $this->constraints);
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function defaultValue(): mixed
    {
        return $this->default;
    }

    /**
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints
     */
    protected function withConstraints(array $constraints): static
    {
        return new self($this->key, $this->column, $this->delimiter, $this->default, $this->hasDefault, $constraints);
    }
}
