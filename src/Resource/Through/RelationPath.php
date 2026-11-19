<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Through;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\RelationHop;

/**
 * A `->through()` path resolved against the relation graph by
 * {@see \haddowg\JsonApi\Resource\Filter\ThroughPathResolver}: the relations it walks, named
 * by their API names, and the field of the type it reaches that the filter or sort reads.
 *
 * Every hop means a related row exists, so a filter matches a row only when some row
 * the path reaches satisfies it, and a sort reads `null` for a row whose to-one reaches
 * nothing.
 */
final readonly class RelationPath
{
    /**
     * @param non-empty-list<RelationHop> $hops each relation walked, its `path` the dotted storage path up to and including it
     * @param list<FieldInterface>        $leafFields the field inventory of the type the path reaches
     */
    public function __construct(
        public string $path,
        public array $hops,
        public array $leafFields,
        public ?FieldInterface $leaf = null,
    ) {}

    /**
     * The type the last hop reaches.
     */
    public function leafType(): string
    {
        return $this->hops[\count($this->hops) - 1]->relatedType();
    }

    /**
     * The storage member each hop traverses, in order.
     *
     * @return non-empty-list<string>
     */
    public function storageSegments(): array
    {
        return \array_map(static fn(RelationHop $hop): string => $hop->storage(), $this->hops);
    }

    /**
     * Whether any hop is a to-many.
     */
    public function reachesMany(): bool
    {
        foreach ($this->hops as $hop) {
            if ($hop->relation->isToMany()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The field of the reached type named `$name`, or `null` when it declares none.
     */
    public function field(string $name): ?FieldInterface
    {
        foreach ($this->leafFields as $field) {
            if ($field->name() === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * This path with `$leaf` as the field it reads.
     */
    public function withLeaf(FieldInterface $leaf): self
    {
        return new self($this->path, $this->hops, $this->leafFields, $leaf);
    }

    /**
     * The storage member the leaf field reads on a reached row.
     *
     * @throws \LogicException when the path has no leaf, or its leaf stores nothing
     */
    public function leafColumn(): string
    {
        $column = $this->leaf?->column();
        if ($column === null) {
            throw new \LogicException(\sprintf('The path "%s" has no stored leaf field to read.', $this->path));
        }

        return $column;
    }

    /**
     * The leaf when it is the reached type's id.
     */
    public function leafId(): ?Id
    {
        return $this->leaf instanceof Id ? $this->leaf : null;
    }

    /**
     * The leaf when it is one of the reached type's relations, as an existence filter's is.
     */
    public function leafRelation(): ?RelationInterface
    {
        return $this->leaf instanceof RelationInterface ? $this->leaf : null;
    }

    /**
     * The storage key a wire id names when the leaf is the reached type's id: decoded
     * through its encoder when it declares one, and unchanged otherwise. Any other leaf
     * returns the value unchanged.
     *
     * @throws FilterValueInvalid when the encoder cannot decode the id
     */
    public function storageValue(string $key, mixed $value): mixed
    {
        $encoder = $this->leafId()?->encoder();
        if ($encoder === null) {
            return $value;
        }

        if (!\is_string($value) && !\is_int($value)) {
            throw new FilterValueInvalid($key, [\sprintf('Each value must be the id of a "%s" resource.', $this->leafType())]);
        }

        return $encoder->decode((string) $value)
            ?? throw new FilterValueInvalid($key, [\sprintf('"%s" is not the id of a "%s" resource.', $value, $this->leafType())]);
    }

    /**
     * The rows `$row` reaches through every hop, fanning out across each to-many, as the
     * reference in-memory handlers walk them. `$admit` narrows the rows each hop reaches
     * to the ones the reached type's scope admits, given the dotted storage path walked so
     * far, the rows, and the relation the hop walks.
     *
     * @param (\Closure(string, list<mixed>, ?RelationInterface=): list<mixed>)|null $admit
     *
     * @return list<mixed>
     */
    public function reach(mixed $row, ?\Closure $admit = null): array
    {
        $rows = [$row];
        foreach ($this->hops as $hop) {
            $next = [];
            foreach ($rows as $current) {
                foreach (self::fanOut(Accessor::get($current, $hop->storage())) as $related) {
                    $next[] = $related;
                }
            }
            $rows = $admit !== null && $next !== [] ? $admit($hop->path, $next, $hop->relation) : $next;
            if ($rows === []) {
                return [];
            }
        }

        return $rows;
    }

    /**
     * @return list<mixed>
     */
    private static function fanOut(mixed $related): array
    {
        if ($related === null) {
            return [];
        }

        if (\is_array($related)) {
            return \array_is_list($related) ? $related : [$related];
        }

        if ($related instanceof \Traversable) {
            return \iterator_to_array($related, false);
        }

        return [$related];
    }
}
