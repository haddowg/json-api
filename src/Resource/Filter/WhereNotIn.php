<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches a column against none of a set of values (the negation of
 * {@see WhereIn}) — the built, readonly value object an adapter consumes. Authors
 * declare one with {@see make()}, which returns a mutable {@see WhereNotInBuilder}.
 * A declared `deserializeUsing()` maps the requested members to the values compared
 * ({@see comparedValues()}); an empty compared list excludes nothing.
 */
final readonly class WhereNotIn implements \haddowg\JsonApi\Resource\Through\FiltersThrough, \haddowg\JsonApi\Resource\Deprecatable, \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\ExemplifiedFilter, \haddowg\JsonApi\Resource\Filter\DescribesQueryParameter, \haddowg\JsonApi\Resource\Filter\AcceptsList, \haddowg\JsonApi\Resource\Filter\HasRemovableDefault, \haddowg\JsonApi\Resource\Filter\SupportsSingular, \haddowg\JsonApi\Resource\Filter\TargetsColumn
{
    use \haddowg\JsonApi\Resource\Through\ResolvesThrough;
    use \haddowg\JsonApi\Resource\Filter\DescribesDelimitedList;
    use \haddowg\JsonApi\Resource\Filter\ExposesValueMetadata;
    use \haddowg\JsonApi\Resource\Filter\DeserializesList;

    /**
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints declared value constraints
     * @param (\Closure(list<mixed>): list<mixed>)|null                      $deserialize maps the requested members to the values compared, see {@see comparedValues()}
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
        public bool $deprecated = false,
        public ?ListValue $list = null,
        public ?string $through = null,
        public ?\haddowg\JsonApi\Resource\Through\RelationPath $resolvedThrough = null,
        public ?\Closure $deserialize = null,
    ) {}

    public static function make(string $key, ?string $column = null): WhereNotInBuilder
    {
        return WhereNotInBuilder::make($key, $column);
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

    public function withoutDefault(): static
    {
        return $this->rebuilt(['default' => null, 'hasDefault' => false]);
    }

    protected function throughLeaf(): array
    {
        return [\haddowg\JsonApi\Resource\Through\LeafKind::Value, 'column'];
    }

    public function leafValue(mixed $value): mixed
    {
        return \haddowg\JsonApi\Resource\Through\FilterLeafValues::members($this->resolvedThrough, $this->key, $value, $this->list, $this->delimiter);
    }
}
