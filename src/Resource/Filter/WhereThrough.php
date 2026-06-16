<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows where a value reached by traversing a **dotted relationship path**
 * satisfies a comparison — an **EXISTS-ANY** semi-join: `author.name` keeps a row
 * whose author's name matches; `comments.body` keeps a row that has *some* comment
 * whose body matches; `author.company.name` chains the hops. Every intermediate
 * segment is a relationship (to-one or to-many — both translate identically as
 * "there exists a … whose …"); the final segment is the compared attribute.
 *
 * The wire key carries dots by default — `WhereThrough::make('author.name')`
 * responds to `filter[author.name]` — or supply an explicit key:
 * `WhereThrough::make('topAuthor', 'author.name')` → `filter[topAuthor]`. Both
 * positional slots are taken, so the comparison operator is the fluent
 * {@see operator()} setter (default `=`), with the same vocabulary as {@see Where}.
 *
 * Like {@see WhereHas} this is data-layer-specific: core ships the metadata and a
 * reference in-memory traversal; database adapters interpret the path as a
 * correlated EXISTS subquery (never a fetch-join, so it neither hydrates the
 * relation nor multiplies rows).
 */
final readonly class WhereThrough implements \haddowg\JsonApi\Resource\Filter\FilterInterface
{
    use \haddowg\JsonApi\Resource\Filter\HasValueConstraints;

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
    ) {}

    /**
     * Single-argument form uses the dotted path as both the wire key and the
     * traversal path (`make('author.name')` → `filter[author.name]`); supply a
     * second argument to override the key (`make('topAuthor', 'author.name')`).
     */
    public static function make(string $key, ?string $path = null): self
    {
        return new self($key, $path ?? $key);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * Sets the comparison operator applied at the leaf segment. Same vocabulary as
     * {@see Where} (`=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `like`); immutable.
     */
    public function operator(string $operator): self
    {
        return new self($this->key, $this->path, $operator, $this->deserialize, $this->constraints);
    }

    /**
     * @param \Closure(mixed): mixed $deserialize
     */
    public function deserializeUsing(\Closure $deserialize): self
    {
        return new self($this->key, $this->path, $this->operator, $deserialize, $this->constraints);
    }

    /**
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints
     */
    protected function withConstraints(array $constraints): static
    {
        return new self($this->key, $this->path, $this->operator, $this->deserialize, $constraints);
    }
}
