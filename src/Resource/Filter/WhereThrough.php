<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows where a value reached by traversing a **dotted relationship path**
 * satisfies a comparison — an **EXISTS-ANY** semi-join: `author.name` keeps a row
 * whose author's name matches; `comments.body` keeps a row that has *some* comment
 * whose body matches; `author.company.name` chains the hops. Every intermediate
 * segment is a relationship (to-one or to-many — both translate identically as
 * "there exists a … whose …"); the final segment is the compared attribute. The
 * built, readonly value object an adapter consumes; authors declare one with
 * {@see make()}, which returns a mutable {@see WhereThroughBuilder}.
 *
 * The wire key carries dots by default — `WhereThrough::make('author.name')`
 * responds to `filter[author.name]` — or supply an explicit key:
 * `WhereThrough::make('topAuthor', 'author.name')` → `filter[topAuthor]`. Both
 * positional slots are taken, so the comparison operator is the fluent
 * {@see WhereThroughBuilder::operator()} setter (default `=`), with the same
 * vocabulary as {@see Where}.
 *
 * The path may also end on a monomorphic relationship (`campaign.advertiser`, or a
 * bare `advertiser`), once a {@see ThroughPathResolver} has resolved it: the filter
 * then compares the related resource's id, a wire id that {@see storageValue()} decodes
 * through the related type's encoder. Only the equality operators compare an id.
 *
 * Like {@see WhereHas} this is data-layer-specific: core ships the metadata and a
 * reference in-memory traversal; database adapters interpret the path as a
 * correlated EXISTS subquery (never a fetch-join, so it neither hydrates the
 * relation nor multiplies rows).
 */
final readonly class WhereThrough implements \haddowg\JsonApi\Resource\Deprecatable, \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\ExemplifiedFilter, \haddowg\JsonApi\Resource\Filter\TraversesPath
{
    use \haddowg\JsonApi\Resource\Filter\ExposesValueMetadata;

    /**
     * The operators that can compare a related resource's id.
     */
    private const array ID_OPERATORS = ['=', '==', '===', '!=', '<>'];

    /**
     * @param \Closure(mixed): mixed|null                                   $deserialize optional value transformer applied before comparison
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints declared value constraints
     */
    public function __construct(
        public string $key,
        public string $path,
        public string $operator = '=',
        public ?\Closure $deserialize = null,
        public array $constraints = [],
        public ?string $description = null,
        public bool $hasExample = false,
        public mixed $example = null,
        public bool $deprecated = false,
        public ?ThroughPath $resolved = null,
    ) {}

    /**
     * Single-argument form uses the dotted path as both the wire key and the
     * traversal path (`make('author.name')` → `filter[author.name]`); supply a
     * second argument to override the key (`make('topAuthor', 'author.name')`).
     */
    public static function make(string $key, ?string $path = null): WhereThroughBuilder
    {
        return WhereThroughBuilder::make($key, $path);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * The declared value constraints, or, for a path resolved to a relationship leaf
     * that declares none, the related id's format ({@see ThroughPath::idConstraints()}).
     *
     * @return list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    public function constraints(): array
    {
        return $this->constraints === [] && $this->resolved !== null ? $this->resolved->idConstraints() : $this->constraints;
    }

    public function throughPath(): string
    {
        return $this->path;
    }

    public function resolvedPath(): ?ThroughPath
    {
        return $this->resolved;
    }

    /**
     * @throws \LogicException when the path ends on a relationship and the filter declares a value transformer or a non-equality operator
     */
    public function withResolvedPath(ThroughPath $path): static
    {
        if ($path->endsOnRelation()) {
            if ($this->deserialize !== null) {
                throw new \LogicException(\sprintf('WhereThrough filter "%s" ends its path "%s" on a relationship, whose id is decoded through the related type\'s encoder; drop deserializeUsing().', $this->key, $this->path));
            }
            if (!\in_array($this->operator, self::ID_OPERATORS, true)) {
                throw new \LogicException(\sprintf('WhereThrough filter "%s" ends its path "%s" on a relationship, so it compares an id and cannot use the "%s" operator.', $this->key, $this->path, $this->operator));
            }
        }

        return new self($this->key, $this->path, $this->operator, $this->deserialize, $this->constraints, $this->description, $this->hasExample, $this->example, $this->deprecated, $path);
    }

    /**
     * The value a data layer compares at the leaf: for a relationship leaf the related
     * storage key the wire id decodes to, otherwise the value through the declared
     * transformer, or unchanged.
     *
     * @throws \haddowg\JsonApi\Exception\FilterValueInvalid when a relationship leaf's id cannot be decoded
     */
    public function storageValue(mixed $value): mixed
    {
        if ($this->resolved !== null && $this->resolved->endsOnRelation()) {
            return $this->resolved->storageValue($this->key, $value);
        }

        return $this->deserialize !== null ? ($this->deserialize)($value) : $value;
    }
}
