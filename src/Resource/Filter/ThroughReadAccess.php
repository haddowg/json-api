<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\ProvidesRelationAuthorizerInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\ThroughReadGateInterface;

/**
 * Resolves the relations a filter reaches through, and which of them the caller may not
 * read, so every data layer refuses the same filters.
 *
 * {@see hopsOf()} lists the {@see RelationHop}s of a {@see TraversesPath} filter, a
 * {@see WhereHas} or {@see WhereDoesntHave}, and every child of a {@see WhereGroup}.
 * {@see hopsAlong()} does the same for any dotted storage path, such as a sort's.
 * {@see refused()} asks the server's {@see ThroughReadGateInterface} about each hop.
 *
 * A segment names the relation stored under it, the one named after it when several
 * share the storage member. When several share it and none is named after the segment,
 * each of them is a hop, so the strictest of their rules applies.
 */
final class ThroughReadAccess
{
    public function __construct(
        private readonly ThroughPathResolver $resolver,
        private readonly ?ThroughReadGateInterface $gate = null,
    ) {}

    /**
     * An access resolver over the registered resources, asking the gate the resolver's
     * relation authorizer implements, if it does.
     */
    public static function forResolver(SerializerResolverInterface $resolver): self
    {
        $authorizer = $resolver instanceof ProvidesRelationAuthorizerInterface ? $resolver->relationAuthorizer() : null;

        return new self(ThroughPathResolver::forResolver($resolver), $authorizer instanceof ThroughReadGateInterface ? $authorizer : null);
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
     * its filter per request, so pass the filter it resolved.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @return list<RelationHop>
     */
    public function hopsOf(FilterInterface $filter, iterable $fields, string $rootType): array
    {
        return match (true) {
            $filter instanceof TraversesPath => $this->hopsAlong($filter->throughPath(), $fields, $rootType),
            $filter instanceof WhereHas, $filter instanceof WhereDoesntHave => $this->hopsAlong($filter->relationship, $fields, $rootType),
            $filter instanceof WhereGroup => \array_merge([], ...\array_map(fn(FilterInterface $child): array => $this->hopsOf($child, $fields, $rootType), $filter->children)),
            default => [],
        };
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
