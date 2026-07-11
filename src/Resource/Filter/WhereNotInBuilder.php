<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see WhereNotIn} value filter.
 * {@see build()} freezes the accumulated state into the readonly {@see WhereNotIn}
 * value object.
 */
final class WhereNotInBuilder implements FilterBuilderInterface
{
    use BuildsValueConstraints;

    private string $column;

    private ?string $delimiter = null;

    private bool $singular = false;

    private mixed $default = null;

    private bool $hasDefault = false;

    public function __construct(
        private string $key,
        ?string $column = null,
    ) {
        $this->column = $column ?? $key;
    }

    public static function make(string $key, ?string $column = null): self
    {
        return new self($key, $column);
    }

    public function build(): WhereNotIn
    {
        return new WhereNotIn($this->key, $this->column, $this->delimiter, $this->singular, $this->default, $this->hasDefault, $this->constraints, $this->description, $this->hasExample, $this->example);
    }

    public function delimiter(string $delimiter): self
    {
        $this->delimiter = $delimiter;

        return $this;
    }

    /**
     * Marks this filter as yielding a zero-to-one result: when the client applies
     * it, the collection renders as a single resource object or `null`, not an
     * array. See {@see SupportsSingular}.
     */
    public function singular(): self
    {
        $this->singular = true;

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
