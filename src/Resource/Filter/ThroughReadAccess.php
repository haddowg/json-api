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
            $filter = $this->resolver->resolve($filter, $fields, $under);
        }
        if ($filter instanceof FiltersThrough && ($path = $filter->resolvedThrough()) !== null) {
            return self::hopsThrough($path, $rootType);
        }

        return match (true) {
            $filter instanceof TraversesPath => $this->hopsAlong($filter->throughPath(), $fields, $rootType),
            $filter instanceof WhereHas, $filter instanceof WhereDoesntHave => $this->hopsAlong($filter->relationship, $fields, $rootType),
            $filter instanceof WhereGroup => \array_merge([], ...\array_map(fn(FilterInterface $child): array => $this->hopsOf($child, $fields, $rootType, $under), $filter->children)),
            default => [],
        };
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
        $sort = $this->resolver->resolveSort($sort, $fields, $under);

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
