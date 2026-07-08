<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches a column against a value with a comparison operator (default `=`).
 *
 * Not `final`: the intent-named convenience filters
 * ({@see Contains}, {@see GreaterThan}, {@see Boolean}, …) are thin subclasses
 * that preset the operator, a typed value deserializer and the matching value
 * constraint, so a handler's existing `instanceof Where` arm dispatches them
 * unchanged. The withers construct via `new static(...)`, so a subclass keeps
 * its own identity (and thus its preset OpenAPI value schema) across a fluent
 * `->describedAs()` / `->default()` / `->singular()` refinement. The convenience
 * subclasses preset their state via `make()` (and the fluent withers) only and
 * never widen the constructor, so `new static(...)` is safe.
 *
 * @phpstan-consistent-constructor
 */
readonly class Where implements \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\HasDefaultValue, \haddowg\JsonApi\Resource\Filter\SupportsSingular, \haddowg\JsonApi\Resource\Filter\PresenceTriggeredFilter
{
    use \haddowg\JsonApi\Resource\Filter\HasValueConstraints;

    /**
     * @param \Closure(mixed): mixed|null                                     $deserialize optional value transformer applied before comparison
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints declared value constraints
     * @param mixed                                                          $fixedValue  the value pinned by {@see fixed()} (only meaningful when `$hasFixed`)
     * @param bool                                                           $hasFixed    whether the compared value is pinned by {@see fixed()}
     */
    public function __construct(
        public string $key,
        public string $column,
        public string $operator = '=',
        public ?\Closure $deserialize = null,
        public bool $singular = false,
        public mixed $default = null,
        public bool $hasDefault = false,
        public array $constraints = [],
        public ?string $description = null,
        public bool $hasExample = false,
        public mixed $example = null,
        public mixed $fixedValue = null,
        public bool $hasFixed = false,
    ) {}

    public static function make(string $key, ?string $column = null, string $operator = '='): static
    {
        return new static($key, $column ?? $key, $operator);
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
    public function singular(): static
    {
        return new static($this->key, $this->column, $this->operator, $this->deserialize, true, $this->default, $this->hasDefault, $this->constraints, $this->description, $this->hasExample, $this->example, $this->fixedValue, $this->hasFixed);
    }

    public function isSingular(): bool
    {
        return $this->singular;
    }

    /**
     * @param \Closure(mixed): mixed $deserialize
     */
    public function deserializeUsing(\Closure $deserialize): static
    {
        return new static($this->key, $this->column, $this->operator, $deserialize, $this->singular, $this->default, $this->hasDefault, $this->constraints, $this->description, $this->hasExample, $this->example, $this->fixedValue, $this->hasFixed);
    }

    /**
     * Coerces the incoming value to a boolean before comparison.
     */
    public function asBoolean(): static
    {
        return $this->deserializeUsing(static fn(mixed $value): bool => \filter_var($value, \FILTER_VALIDATE_BOOLEAN));
    }

    /**
     * Declares the value to apply when the request omits this filter's key —
     * a requested value always wins ({@see HasDefaultValue}).
     */
    public function default(mixed $value): static
    {
        return new static($this->key, $this->column, $this->operator, $this->deserialize, $this->singular, $value, true, $this->constraints, $this->description, $this->hasExample, $this->example, $this->fixedValue, $this->hasFixed);
    }

    /**
     * Pins the compared value: the request value is **ignored** and this filter
     * becomes a **presence trigger** — `filter[<key>]` present with any value
     * applies `column <operator> <value>`, and omitting the key does not apply it.
     * Distinct from {@see default()}, which the client *can* override: a fixed
     * value is one the client cannot influence at all.
     *
     * The pinned value is recorded as real state ({@see $fixedValue} /
     * {@see $hasFixed}) so the OpenAPI projector can document the parameter
     * honestly as server-applied. Execution rides the **existing** deserialize
     * seam — the compared value becomes a constant-returning closure — so no
     * filter handler needs a dedicated arm to run a fixed filter; the built-in
     * `Where` arm compares against the constant unchanged. Because the request
     * value carries no meaning, any declared value constraints are dropped (there
     * is no client input to validate).
     */
    public function fixed(mixed $value): static
    {
        return new static($this->key, $this->column, $this->operator, static fn(): mixed => $value, $this->singular, $this->default, $this->hasDefault, [], $this->description, $this->hasExample, $this->example, $value, true);
    }

    public function isPresenceTriggered(): bool
    {
        return $this->hasFixed;
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
        return new static($this->key, $this->column, $this->operator, $this->deserialize, $this->singular, $this->default, $this->hasDefault, $constraints, $this->description, $this->hasExample, $this->example, $this->fixedValue, $this->hasFixed);
    }

    protected function withDescriptionAndExample(?string $description, bool $hasExample, mixed $example): static
    {
        return new static($this->key, $this->column, $this->operator, $this->deserialize, $this->singular, $this->default, $this->hasDefault, $this->constraints, $description, $hasExample, $example, $this->fixedValue, $this->hasFixed);
    }
}
