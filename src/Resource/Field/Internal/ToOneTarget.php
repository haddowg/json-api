<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field\Internal;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationshipLinkage;
use haddowg\JsonApi\Serializer\ToOneRelationshipLinkageInterface;

/**
 * The one place core asks the injected to-one linkage seam for a relation's target.
 *
 * @internal
 */
final class ToOneTarget
{
    /**
     * The host-supplied target for to-one `$relation` on `$model`, or `null` when no
     * seam is injected or it supplies nothing for this pair.
     */
    public static function supplied(
        RelationInterface $relation,
        mixed $model,
        JsonApiRequestInterface $request,
        ?SerializerResolverInterface $resolver,
    ): ?ToOneRelationshipLinkage {
        $source = $resolver?->relationshipLinkage();
        if (!$source instanceof ToOneRelationshipLinkageInterface) {
            return null;
        }

        return $source->toOneLinkageForRelationship($model, $relation, $request);
    }

    /**
     * The related object to render for to-one `$relation` on `$model`: the
     * host-supplied target when there is one, otherwise the relation's own read.
     */
    public static function read(
        RelationInterface $relation,
        mixed $model,
        JsonApiRequestInterface $request,
        ?SerializerResolverInterface $resolver,
    ): mixed {
        $supplied = self::supplied($relation, $model, $request, $resolver);

        return $supplied !== null ? $supplied->target : $relation->readValue($model, $request);
    }
}
