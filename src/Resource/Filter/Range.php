<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows whose column falls within an **inclusive range**, expressed as a
 * structured value with an optional lower and upper bound: `min <= value <= max`.
 * Either bound may be omitted, so an open-ended range works — `min` alone is a
 * `>=`, `max` alone a `<=`, and an entirely absent value is a no-op.
 *
 * Unlike the scalar filters this is a **genuinely new filter type**, not a
 * {@see Where} preset: its wire value is **nested** —
 * `?filter[<key>][min]=10&filter[<key>][max]=100` (Symfony parses this into
 * `['min' => '10', 'max' => '100']`) — and its apply runs two predicates, so a
 * handler needs a dedicated `instanceof Range` arm. The optional `deserialize`
 * closure is applied to **each present bound and to the column value** before
 * comparison, so numeric/temporal ranges compare numerically/temporally rather
 * than lexically; {@see DateRange} is a `Range` whose deserializer coerces each
 * bound ISO-8601 → `\DateTimeImmutable`.
 *
 * Like {@see WhereThrough} this is data-layer-specific: core ships the metadata
 * and the reference in-memory apply; database adapters translate it into two
 * push-down `andWhere` predicates.
 *
 * {@see DateRange} is the only subclass and never widens the constructor, so the
 * withers' `new static(...)` is safe.
 *
 * @phpstan-consistent-constructor
 */
readonly class Range implements \haddowg\JsonApi\Resource\Filter\DescribedFilter
{
    use \haddowg\JsonApi\Resource\Filter\HasValueConstraints;

    /**
     * @param \Closure(mixed): mixed|null                                   $deserialize optional value transformer applied to each bound and the column value before comparison
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints declared value constraints (applied to each present bound)
     */
    public function __construct(
        public string $key,
        public string $column,
        public ?\Closure $deserialize = null,
        public array $constraints = [],
        public ?string $description = null,
        public bool $hasExample = false,
        public mixed $example = null,
    ) {}

    public static function make(string $key, ?string $column = null): static
    {
        return (new static($key, $column ?? $key))
            ->deserializeUsing(\haddowg\JsonApi\Resource\Filter\NumericCoercion::deserializer())
            ->numeric()
            ->describedAs('Matches values within the given inclusive numeric range (min/max, either optional).');
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @param \Closure(mixed): mixed $deserialize
     */
    public function deserializeUsing(\Closure $deserialize): static
    {
        return new static($this->key, $this->column, $deserialize, $this->constraints, $this->description, $this->hasExample, $this->example);
    }

    /**
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints
     */
    protected function withConstraints(array $constraints): static
    {
        return new static($this->key, $this->column, $this->deserialize, $constraints, $this->description, $this->hasExample, $this->example);
    }

    protected function withDescriptionAndExample(?string $description, bool $hasExample, mixed $example): static
    {
        return new static($this->key, $this->column, $this->deserialize, $this->constraints, $description, $hasExample, $example);
    }
}
