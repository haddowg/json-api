<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches a column against none of a set of values (the negation of
 * {@see WhereIn}) — the built, readonly value object an adapter consumes. Authors
 * declare one with {@see make()}, which returns a mutable {@see WhereNotInBuilder}.
 */
final readonly class WhereNotIn implements \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\HasDefaultValue, \haddowg\JsonApi\Resource\Filter\SupportsSingular
{
    use \haddowg\JsonApi\Resource\Filter\ExposesValueMetadata;

    /**
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints declared value constraints
     */
    public function __construct(
        public string $key,
        public string $column,
        public ?string $delimiter = null,
        public bool $singular = false,
        public mixed $default = null,
        public bool $hasDefault = false,
        public array $constraints = [],
        public ?string $description = null,
        public bool $hasExample = false,
        public mixed $example = null,
    ) {}

    public static function make(string $key, ?string $column = null): WhereNotInBuilder
    {
        return WhereNotInBuilder::make($key, $column);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isSingular(): bool
    {
        return $this->singular;
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
