<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see Where} value filter: the fluent object
 * an author chains (`Where::make('status')->singular()->default('active')`).
 * {@see build()} freezes the accumulated state into the readonly {@see Where} value
 * object an adapter consumes.
 *
 * Non-final by design: the intent-named convenience facades ({@see Contains},
 * {@see StartsWith}, {@see EndsWith}, {@see Numeric}, {@see GreaterThan},
 * {@see GreaterThanOrEqual}, {@see LessThan}, {@see LessThanOrEqual}, {@see Boolean})
 * extend it to preset the operator / value-deserializer / matching constraint —
 * they all build the same base {@see Where}.
 *
 * @phpstan-consistent-constructor
 */
class WhereBuilder implements FilterBuilderInterface
{
    use BuildsValueConstraints;

    protected string $column;

    /**
     * @var \Closure(mixed): mixed|null
     */
    protected ?\Closure $deserialize = null;

    protected bool $singular = false;

    protected mixed $default = null;

    protected bool $hasDefault = false;

    protected mixed $fixedValue = null;

    protected bool $hasFixed = false;

    public function __construct(
        protected string $key,
        ?string $column = null,
        protected string $operator = '=',
    ) {
        $this->column = $column ?? $key;
    }

    public static function make(string $key, ?string $column = null, string $operator = '='): static
    {
        return new static($key, $column, $operator);
    }

    public function build(): Where
    {
        return new Where(
            $this->key,
            $this->column,
            $this->operator,
            $this->deserialize,
            $this->singular,
            $this->default,
            $this->hasDefault,
            $this->constraints,
            $this->description,
            $this->hasExample,
            $this->example,
            $this->fixedValue,
            $this->hasFixed,
        );
    }

    /**
     * Marks this filter as yielding a zero-to-one result: when the client applies
     * it, the collection renders as a single resource object or `null`, not an
     * array. Use on a unique attribute (a slug, a UUID) — see {@see SupportsSingular}.
     *
     * @return static
     */
    public function singular(): static
    {
        $this->singular = true;

        return $this;
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

    /**
     * Coerces the incoming value to a boolean before comparison.
     *
     * @return static
     */
    public function asBoolean(): static
    {
        return $this->deserializeUsing(static fn(mixed $value): bool => \filter_var($value, \FILTER_VALIDATE_BOOLEAN));
    }

    /**
     * Declares the value to apply when the request omits this filter's key —
     * a requested value always wins ({@see HasDefaultValue}).
     *
     * @return static
     */
    public function default(mixed $value): static
    {
        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    /**
     * Pins the compared value: the request value is **ignored** and this filter
     * becomes a **presence trigger** — `filter[<key>]` present with any value
     * applies `column <operator> <value>`, and omitting the key does not apply it.
     * Distinct from {@see default()}, which the client *can* override: a fixed
     * value is one the client cannot influence at all.
     *
     * The pinned value is recorded as real state ({@see Where::$fixedValue} /
     * {@see Where::$hasFixed}) so the OpenAPI projector can document the parameter
     * honestly as server-applied. Execution rides the **existing** deserialize
     * seam — the compared value becomes a constant-returning closure — so no
     * filter handler needs a dedicated arm to run a fixed filter; the built-in
     * `Where` arm compares against the constant unchanged. Because the request
     * value carries no meaning, any declared value constraints are dropped (there
     * is no client input to validate).
     *
     * @return static
     */
    public function fixed(mixed $value): static
    {
        $this->deserialize = static fn(): mixed => $value;
        $this->fixedValue = $value;
        $this->hasFixed = true;
        $this->constraints = [];

        return $this;
    }
}
