<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see Range} value filter. {@see make()}
 * presets a numeric coercion deserializer and a `numeric()` bound constraint;
 * {@see build()} freezes the accumulated state into the readonly {@see Range} value
 * object an adapter consumes.
 *
 * Non-final by design: {@see DateRangeBuilder} extends it to preset an ISO-8601
 * temporal deserializer/constraint and build a {@see DateRange} instead.
 *
 * @phpstan-consistent-constructor
 */
class RangeBuilder implements FilterBuilderInterface
{
    use BuildsValueConstraints;

    protected string $column;

    /**
     * @var \Closure(mixed): mixed|null
     */
    protected ?\Closure $deserialize = null;

    public function __construct(
        protected string $key,
        ?string $column = null,
    ) {
        $this->column = $column ?? $key;
    }

    public static function make(string $key, ?string $column = null): static
    {
        return (new static($key, $column))
            ->deserializeUsing(NumericCoercion::deserializer())
            ->numeric()
            ->describedAs('Matches values within the given inclusive numeric range (min/max, either optional).');
    }

    public function build(): Range
    {
        return new Range($this->key, $this->column, $this->deserialize, $this->constraints, $this->description, $this->hasExample, $this->example);
    }

    /**
     * @param \Closure(mixed): mixed $deserialize
     * @return static
     */
    public function deserializeUsing(\Closure $deserialize): static
    {
        $this->deserialize = $deserialize;

        return $this;
    }
}
