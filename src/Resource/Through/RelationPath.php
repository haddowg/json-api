<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Through;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\RelationHop;
use haddowg\JsonApi\Resource\Filter\ThroughPath;

/**
 * A `->through()` path resolved against the relation graph by
 * {@see \haddowg\JsonApi\Resource\Filter\ThroughPathResolver}: the relations it walks, named
 * by their API names, and the field of the type it reaches that the filter or sort reads.
 *
 * Every hop means a related row exists, so a filter matches a row only when some row
 * the path reaches satisfies it, and a sort reads `null` for a row whose to-one reaches
 * nothing.
 *
 * A `pivot` segment reaches the pivot rows of the relation before it, or of the relation
 * the filter or sort runs under when it leads the path, as a {@see RelationHop} marked
 * `pivot`. A path that ends there reads one of that relation's declared pivot fields; one
 * that goes on walks a relation the pivot row holds, which the data layer names through
 * {@see PivotRowRelations}.
 */
final readonly class RelationPath
{
    /**
     * The segment naming the pivot row of the relation before it.
     */
    public const string PIVOT = 'pivot';

    /**
     * @param non-empty-list<RelationHop>                    $hops             each relation walked, its `path` the dotted storage path up to and including it
     * @param list<FieldInterface>                           $leafFields       the field inventory of the type the path reaches, or the pivot fields when it ends on a pivot row
     * @param (\Closure(string): ?RelationInterface)|null    $pivotRowRelation the relation the pivot row the path ends on holds under a member, when it ends on one
     */
    public function __construct(
        public string $path,
        public array $hops,
        public array $leafFields,
        public ?FieldInterface $leaf = null,
        public ?\Closure $pivotRowRelation = null,
    ) {}

    /**
     * Whether `$relation` has pivot rows a `pivot` segment can reach: a {@see BelongsToMany}
     * over an association class or with stored pivot fields, or another
     * {@see PivotRelationInterface} with stored pivot fields.
     *
     * @phpstan-assert-if-true PivotRelationInterface $relation
     */
    public static function hasPivotRow(?RelationInterface $relation): bool
    {
        if (!$relation instanceof PivotRelationInterface) {
            return false;
        }

        return $relation->storedPivotFields() !== [] || ($relation instanceof BelongsToMany && $relation->pivotThrough() !== null);
    }

    /**
     * Whether the path starts at the pivot row of the relation it runs under.
     */
    public function startsWithPivot(): bool
    {
        return $this->hops[0]->pivot && ThroughPath::split($this->path)[0] === self::PIVOT;
    }

    /**
     * Whether the path ends on a pivot row, so its leaf is a pivot field.
     */
    public function endsOnPivot(): bool
    {
        return $this->hops[\count($this->hops) - 1]->pivot;
    }

    /**
     * Whether any hop reaches a pivot row.
     */
    public function reachesPivot(): bool
    {
        foreach ($this->hops as $hop) {
            if ($hop->pivot) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$hop` reaches many rows from each row before it: a to-many relation, or a
     * pivot hop anywhere but at the start of the path.
     */
    public function isToManyHop(int $index): bool
    {
        $hop = $this->hops[$index];

        return $hop->pivot ? !($index === 0 && $this->startsWithPivot()) : $hop->relation->isToMany();
    }

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
        foreach (\array_keys($this->hops) as $index) {
            if ($this->isToManyHop($index)) {
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

        return $this->pivotRowRelation !== null ? ($this->pivotRowRelation)($name) : null;
    }

    /**
     * This path with `$leaf` as the field it reads.
     */
    public function withLeaf(FieldInterface $leaf): self
    {
        return new self($this->path, $this->hops, $this->leafFields, $leaf, $this->pivotRowRelation);
    }

    /**
     * What the path reaches, for a message: the type it reaches, or the pivot of the
     * relation whose pivot row it ends on.
     */
    public function describeLeaf(): string
    {
        $last = $this->hops[\count($this->hops) - 1];

        return $last->pivot
            ? \sprintf('the pivot of relation "%s"', $last->relation->name())
            : \sprintf('type "%s"', $last->relatedType());
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
     *
     * @throws \LogicException when the path reaches a pivot row
     */
    public function reach(mixed $row, ?\Closure $admit = null): array
    {
        if ($this->reachesPivot()) {
            throw new \LogicException(\sprintf('The path "%s" reaches a pivot row, which an in-memory row holds no member for; only a data layer that reads pivot rows can follow it.', $this->path));
        }

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
