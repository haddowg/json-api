<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches the resource id against any value in a set — the built, readonly value
 * object an adapter consumes. Equivalent to a {@see WhereIn} targeting the id
 * column; ships as a dedicated type because id filtering is the most common case.
 * Authors declare one with {@see make()}, which returns a mutable {@see WhereIdInBuilder}.
 */
final readonly class WhereIdIn implements \haddowg\JsonApi\Resource\Deprecatable, \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\ExemplifiedFilter, \haddowg\JsonApi\Resource\Filter\DescribesQueryParameter, \haddowg\JsonApi\Resource\Filter\AcceptsList, \haddowg\JsonApi\Resource\Filter\HasRemovableDefault, \haddowg\JsonApi\Resource\Filter\TargetsColumn
{
    use \haddowg\JsonApi\Resource\Filter\DescribesDelimitedList;
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
        public bool $deprecated = false,
        public ?ListValue $list = null,
    ) {}

    public static function make(string $key = 'id', string $column = 'id'): WhereIdInBuilder
    {
        return WhereIdInBuilder::make($key, $column);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function listValue(): ?ListValue
    {
        return $this->list;
    }

    public function targetColumn(): string
    {
        return $this->column;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function defaultValue(): mixed
    {
        return $this->default;
    }

    public function withoutDefault(): static
    {
        return new self(
            key: $this->key,
            column: $this->column,
            delimiter: $this->delimiter,
            default: null,
            hasDefault: false,
            constraints: $this->constraints,
            description: $this->description,
            hasExample: $this->hasExample,
            example: $this->example,
            deprecated: $this->deprecated,
            list: $this->list,
        );
    }
}
