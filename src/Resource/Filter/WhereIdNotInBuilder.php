<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see WhereIdNotIn} value filter.
 * {@see build()} freezes the accumulated state into the readonly {@see WhereIdNotIn}
 * value object.
 */
final class WhereIdNotInBuilder implements FilterBuilderInterface
{
    use BuildsValueConstraints;

    private ?string $delimiter = null;

    private mixed $default = null;

    private bool $hasDefault = false;

    public function __construct(
        private string $key = 'id',
        private string $column = 'id',
    ) {}

    public static function make(string $key = 'id', string $column = 'id'): self
    {
        return new self($key, $column);
    }

    public function build(): WhereIdNotIn
    {
        return new WhereIdNotIn($this->key, $this->column, $this->delimiter, $this->default, $this->hasDefault, $this->constraints, $this->description, $this->hasExample, $this->example);
    }

    public function delimiter(string $delimiter): self
    {
        $this->delimiter = $delimiter;

        return $this;
    }

    /**
     * Declares the value to apply when the request omits this filter's key —
     * a requested value always wins ({@see HasDefaultValue}). Shape it as the
     * request would carry it (an array, or a string the declared delimiter splits).
     */
    public function default(mixed $value): self
    {
        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }
}
