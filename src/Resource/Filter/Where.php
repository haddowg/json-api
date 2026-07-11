<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches a column against a value with a comparison operator (default `=`) — the
 * built, readonly value object an adapter consumes (by its public readonly
 * properties: `->key`, `->column`, `->operator`, `->deserialize`, …). Authors
 * declare one with {@see make()}, which returns a mutable {@see WhereBuilder}; the
 * resource **builds** it into this value object before use.
 *
 * The intent-named convenience filters ({@see Contains}, {@see GreaterThan},
 * {@see Boolean}, …) are {@see WhereBuilder} facades that preset the operator, a
 * typed value deserializer and the matching value constraint and build a plain
 * `Where`, so a handler's existing `instanceof Where` arm dispatches them unchanged.
 */
final readonly class Where implements \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\HasDefaultValue, \haddowg\JsonApi\Resource\Filter\SupportsSingular, \haddowg\JsonApi\Resource\Filter\PresenceTriggeredFilter
{
    use \haddowg\JsonApi\Resource\Filter\ExposesValueMetadata;

    /**
     * @param \Closure(mixed): mixed|null                                     $deserialize optional value transformer applied before comparison
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints declared value constraints
     * @param mixed                                                          $fixedValue  the value pinned by {@see WhereBuilder::fixed()} (only meaningful when `$hasFixed`)
     * @param bool                                                           $hasFixed    whether the compared value is pinned by {@see WhereBuilder::fixed()}
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

    public static function make(string $key, ?string $column = null, string $operator = '='): WhereBuilder
    {
        return WhereBuilder::make($key, $column, $operator);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isSingular(): bool
    {
        return $this->singular;
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
}
