<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\ProvidesRelationAuthorizerInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Through\FiltersThrough;
use haddowg\JsonApi\Resource\Through\PivotRowRelations;
use haddowg\JsonApi\Resource\Through\RelationPath;
use haddowg\JsonApi\Resource\Through\SortsThrough;
use haddowg\JsonApi\Resource\ThroughReadGateInterface;

/**
 * Resolves the relations a filter reaches through, and which of them the caller may not
 * read, so every data layer refuses the same filters.
 *
 * {@see hopsOf()} lists the {@see RelationHop}s of a {@see TraversesPath} filter, a
 * {@see WhereHas} or {@see WhereDoesntHave}, and every child of a {@see WhereGroup}.
 * {@see hopsAlong()} does the same for any dotted storage path. A filter or sort declared
 * with `->through()` reaches each relation of its resolved path ({@see hopsOfSort()} for
 * a sort), and an existence filter at the end of one also the relation it tests.
 * {@see refused()} asks the server's {@see ThroughReadGateInterface} about each hop.
 *
 * A segment names the relation stored under it, the one named after it when several
 * share the storage member. When several share it and none is named after the segment,
 * each of them is a hop, so the strictest of their rules applies.
 *
 * A `pivot` segment is a hop of the relation whose pivot rows it reaches, so a pivot row,
 * which has no type, is read under that relation's permission. A relation the pivot row
 * holds is a hop like any other, under its related type's rules.
 *
 * A {@see WhereMorph} reaches through its polymorphic relation to the type a request
 * names, so each of its hops names one type as its target: {@see hopsOf()} lists one for
 * every type the relation declares, the hops some request can reach, and
 * {@see hopsOfRequested()} the one a requested value names.
 */
final class ThroughReadAccess
{
    public function __construct(
        private readonly ThroughPathResolver $resolver,
        private readonly ?ThroughReadGateInterface $gate = null,
    ) {}

    /**
     * An access resolver over the registered resources, asking the gate the resolver's
     * relation authorizer implements, if it does, and reading the relations a pivot row
     * holds from `$pivotRows`.
     */
    public static function forResolver(SerializerResolverInterface $resolver, ?PivotRowRelations $pivotRows = null): self
    {
        $authorizer = $resolver instanceof ProvidesRelationAuthorizerInterface ? $resolver->relationAuthorizer() : null;

        return new self(ThroughPathResolver::forResolver($resolver, $pivotRows), $authorizer instanceof ThroughReadGateInterface ? $authorizer : null);
    }

    public function gate(): ?ThroughReadGateInterface
    {
        return $this->gate;
    }

    /**
     * The hops the dotted storage path `$path` walks from `$rootType`, whose inventory is
     * `$fields`, in path order. A path ending on a field has no hop for its last segment.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @return list<RelationHop>
     */
    public function hopsAlong(string $path, iterable $fields, string $rootType): array
    {
        $segments = ThroughPath::split($path);
        $hops = [];
        $parent = $rootType;
        foreach ($this->resolver->relationsAlong($path, $fields) as $index => $relations) {
            $prefix = \implode('.', \array_slice($segments, 0, $index + 1));
            foreach ($relations as $relation) {
                $hops[] = new RelationHop($relation, $parent, $prefix);
            }
            $parent = $relations[0]->relatedTypes()[0] ?? $parent;
        }

        return $hops;
    }

    /**
     * The hops `$filter` reaches through from `$rootType`, whose inventory is `$fields`:
     * a traversal's path, a relationship existence filter's relation, and those of every
     * child of a group. Any other filter reaches through none. A filter family resolves
     * its filter per request, so pass the filter it resolved. `$under` is the relation the
     * filter runs under, whose pivot row a path starting with `pivot` reaches.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @return list<RelationHop>
     */
    public function hopsOf(FilterInterface $filter, iterable $fields, string $rootType, ?RelationHop $under = null): array
    {
        if ($filter instanceof FiltersThrough) {
            $filter = $this->resolver->resolve($filter, $fields, $under, $rootType);
        }
        if ($filter instanceof FiltersThrough && ($path = $filter->resolvedThrough()) !== null) {
            return self::hopsThrough($path, $rootType);
        }

        if ($filter instanceof WhereMorph) {
            return $this->morphHops($filter, $fields, $rootType, null);
        }

        return match (true) {
            $filter instanceof TraversesPath => $this->hopsAlong($filter->throughPath(), $fields, $rootType),
            $filter instanceof WhereHas, $filter instanceof WhereDoesntHave => $this->hopsAlong($filter->relationship, $fields, $rootType),
            $filter instanceof WhereGroup => \array_merge([], ...\array_map(fn(FilterInterface $child): array => $this->hopsOf($child, $fields, $rootType, $under), $filter->children)),
            default => [],
        };
    }

    /**
     * The hops `$filter` reaches through for the requested `$value`, as {@see hopsOf()}
     * lists them, except that a {@see WhereMorph}, alone or inside a group, reaches only
     * the type the value names. Ask the gate about these before applying a filter the
     * client sent.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @return list<RelationHop>
     *
     * @throws \haddowg\JsonApi\Exception\FilterValueInvalid when a WhereMorph's value names no type the relation declares
     */
    public function hopsOfRequested(FilterInterface $filter, mixed $value, iterable $fields, string $rootType, ?RelationHop $under = null): array
    {
        if ($filter instanceof WhereMorph) {
            return $this->morphHops($filter, $fields, $rootType, $value);
        }

        if ($filter instanceof WhereGroup) {
            return \array_merge([], ...\array_map(fn(FilterInterface $child): array => $this->hopsOfRequested($child, $value, $fields, $rootType, $under), $filter->children));
        }

        return $this->hopsOf($filter, $fields, $rootType, $under);
    }

    /**
     * The hops of a {@see WhereMorph}: its relation, once for each type it declares, or
     * once for the type `$value` names when it is not `null`.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @return list<RelationHop>
     */
    private function morphHops(WhereMorph $filter, iterable $fields, string $rootType, mixed $value): array
    {
        $filter = $this->resolver->resolve($filter, $fields);
        \assert($filter instanceof WhereMorph);
        $target = $filter->resolvedTarget();
        if ($target === null) {
            return [];
        }

        $relation = $target->relation;
        $storage = $relation->column() ?? $relation->name();
        $types = $value === null ? $target->types : [$filter->requested($value)['type']];

        return \array_map(static fn(string $type): RelationHop => new RelationHop($relation, $rootType, $storage, target: $type), $types);
    }

    /**
     * The hops `$sort` reaches through from `$rootType`, whose inventory is `$fields`: a
     * sort declared with `->through()` reaches each relation of its path, any other none.
     * `$under` is the relation the sort runs under.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @return list<RelationHop>
     */
    public function hopsOfSort(SortInterface $sort, iterable $fields, string $rootType, ?RelationHop $under = null): array
    {
        $sort = $this->resolver->resolveSort($sort, $fields, $under, $rootType);

        return $sort instanceof SortsThrough && ($path = $sort->resolvedThrough()) !== null ? self::hopsThrough($path, $rootType) : [];
    }

    /**
     * The hops of a resolved `->through()` path from `$rootType`, and the relation an
     * existence filter tests at its end.
     *
     * @return list<RelationHop>
     */
    private static function hopsThrough(RelationPath $path, string $rootType): array
    {
        $hops = $path->hops;
        // A leading pivot hop is the relation the path runs under, declared on its own parent type.
        if (!$path->startsWithPivot()) {
            $hops[0] = $hops[0]->on($rootType);
        }
        $leaf = $path->leafRelation();
        if ($leaf !== null) {
            $hops[] = new RelationHop($leaf, $path->endsOnPivot() ? '' : $path->leafType(), $hops[\count($hops) - 1]->path . '.' . ($leaf->column() ?? $leaf->name()));
        }

        return \array_values($hops);
    }

    /**
     * The hops of `$hops` the gate says the caller may not read, in order. None without a
     * gate.
     *
     * @param list<RelationHop> $hops
     *
     * @return list<RelationHop>
     */
    public function refused(array $hops, JsonApiRequestInterface $request): array
    {
        if ($this->gate === null) {
            return [];
        }

        return \array_values(\array_filter($hops, fn(RelationHop $hop): bool => !$this->gate->canReadThrough($hop, $request)));
    }
}
