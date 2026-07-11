<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches the resource id against any value in a set — the built, readonly value
 * object an adapter consumes. Equivalent to a {@see WhereIn} targeting the id
 * column; ships as a dedicated type because id filtering is the most common case.
 * Authors declare one with {@see make()}, which returns a mutable {@see WhereIdInBuilder}.
 */
final readonly class WhereIdIn implements \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\HasDefaultValue
{
    use \haddowg\JsonApi\Resource\Filter\ExposesValueMetadata;

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
        public ?string $description = null,
        public bool $hasExample = false,
        public mixed $example = null,
    ) {}

    public static function make(string $key = 'id', string $column = 'id'): WhereIdInBuilder
    {
        return WhereIdInBuilder::make($key, $column);
    }

    public function key(): string
    {
        return $this->key;
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
