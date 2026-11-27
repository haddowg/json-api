<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * Builds the `getRelationships()` callable map from a list of relation fields.
 * Decouples relationship-object assembly from {@see AbstractResource} so a custom
 * serializer can render relations from a standalone field list — every
 * {@see RelationInterface::buildRelationship()} needs only the model, the request
 * and a {@see SerializerResolverInterface}, none of which is owned by the resource
 * base.
 */
trait RendersRelationsTrait
{
    /**
     * Builds the relationship callables keyed by member name: each callable
     * defers to the relation's {@see RelationInterface::buildRelationship()},
     * resolving related serializers through `$resolver`.
     *
     * @param list<RelationInterface> $relations
     *
     * @return array<string, callable(mixed, JsonApiRequestInterface, string): AbstractRelationship>
     */
    protected static function relationshipCallables(array $relations, SerializerResolverInterface $resolver): array
    {
        $map = [];
        foreach ($relations as $relation) {
            $map[$relation->name()] = self::relationshipCallable($relation, $resolver);
        }

        return $map;
    }

    /**
     * The callable for one relation, resolving related serializers through `$resolver`.
     * A relation declared `notSparseField()` keeps rendering whatever `fields[type]`
     * lists, so a decorator that rebuilds one relation through another resolver keeps
     * that behaviour.
     *
     * @return callable(mixed, JsonApiRequestInterface, string): AbstractRelationship
     */
    protected static function relationshipCallable(RelationInterface $relation, SerializerResolverInterface $resolver): callable
    {
        $build = static fn(mixed $model, JsonApiRequestInterface $request, string $name): AbstractRelationship
            => $relation->buildRelationship($model, $request, $resolver);

        return $relation->isSparseField() ? $build : new Internal\NonSparseMember($build);
    }
}
