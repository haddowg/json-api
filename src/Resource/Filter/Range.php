<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows whose column falls within an **inclusive range**, expressed as a
 * structured value with an optional lower and upper bound: `min <= value <= max`.
 * Either bound may be omitted, so an open-ended range works — `min` alone is a
 * `>=`, `max` alone a `<=`, and an entirely absent value is a no-op. The built,
 * readonly value object an adapter consumes; authors declare one with {@see make()},
 * which returns a mutable {@see RangeBuilder}.
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
 * {@see DateRange} is the only subclass and never widens the constructor.
 */
readonly class Range implements \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\DescribesQueryParameter
{
    use \haddowg\JsonApi\Resource\Filter\ExposesValueMetadata;

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

    public static function make(string $key, ?string $column = null): RangeBuilder
    {
        return RangeBuilder::make($key, $column);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function describeQueryParameter(\haddowg\JsonApi\OpenApi\Schema $valueSchema): \haddowg\JsonApi\OpenApi\QueryParameterShape
    {
        // A Range's wire value is the nested object filter[<key>][min]/[max] — an OAS
        // `deepObject` parameter (ADR 0077) whose bounds carry the per-bound value schema.
        $bound = $this->boundSchema($valueSchema);

        return new \haddowg\JsonApi\OpenApi\QueryParameterShape(
            \haddowg\JsonApi\OpenApi\Schema::ofType('object')->withProperties(['min' => $bound, 'max' => $bound]),
            \haddowg\JsonApi\OpenApi\ParameterStyle::DeepObject,
            true,
        );
    }

    /**
     * The JSON Schema for each `min`/`max` bound. A numeric {@see Range}'s bounds carry
     * its declared per-bound value constraints; {@see DateRange} overrides this to a
     * `date-time` string.
     */
    protected function boundSchema(\haddowg\JsonApi\OpenApi\Schema $valueSchema): \haddowg\JsonApi\OpenApi\Schema
    {
        return $valueSchema;
    }
}
