<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches a column against any value in a set. The incoming value is split on
 * {@see $delimiter} (default: already an array, or a comma-delimited string).
 */
final readonly class WhereIn implements \haddowg\JsonApi\Resource\Filter\FilterInterface, \haddowg\JsonApi\Resource\Filter\HasDefaultValue, \haddowg\JsonApi\Resource\Filter\SupportsSingular
{
    public function __construct(
        public string $key,
        public string $column,
        public ?string $delimiter = null,
        public bool $singular = false,
        public mixed $default = null,
        public bool $hasDefault = false,
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
        return new self($this->key, $this->column, $delimiter, $this->singular, $this->default, $this->hasDefault);
    }

    /**
     * Marks this filter as yielding a zero-to-one result: when the client applies
     * it, the collection renders as a single resource object or `null`, not an
     * array. See {@see SupportsSingular}.
     */
    public function singular(): self
    {
        return new self($this->key, $this->column, $this->delimiter, true, $this->default, $this->hasDefault);
    }

    public function isSingular(): bool
    {
        return $this->singular;
    }

    /**
     * Declares the value to apply when the request omits this filter's key —
     * a requested value always wins ({@see HasDefaultValue}). Shape it as the
     * request would carry it (an array, or a string the declared delimiter
     * splits).
     */
    public function default(mixed $value): self
    {
        return new self($this->key, $this->column, $this->delimiter, $this->singular, $value, true);
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
