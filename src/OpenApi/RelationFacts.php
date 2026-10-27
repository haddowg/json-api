<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresPolymorphicRelation;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresRelationUriFieldName;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresWriteOnlyRelation;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;

/**
 * Reads the optional facts a relation's metadata may declare through the opt-in
 * `Declares*` interfaces, so the component, path and link projections agree on them.
 *
 * @internal
 */
final class RelationFacts
{
    /**
     * The segment the relation's related and relationship paths are keyed by.
     */
    public static function uriFieldName(RelationMetadataInterface $relation): string
    {
        return $relation instanceof DeclaresRelationUriFieldName ? $relation->uriFieldName() : $relation->name();
    }

    /**
     * Whether the relation is unconditionally write-only.
     */
    public static function isWriteOnly(RelationMetadataInterface $relation): bool
    {
        return $relation instanceof DeclaresWriteOnlyRelation && $relation->isWriteOnly();
    }

    /**
     * Whether the relation is projected as a union of its related types: it lists more
     * than one, or its metadata declares it polymorphic however many it lists.
     */
    public static function isPolymorphic(RelationMetadataInterface $relation): bool
    {
        return \count($relation->relatedTypes()) > 1
            || ($relation instanceof DeclaresPolymorphicRelation && $relation->isPolymorphic());
    }

    /**
     * Whether the relation is polymorphic by declaration with a single related type, the
     * one shape a count of its types would mistake for a monomorphic relation.
     */
    public static function isSingleTargetPolymorphic(RelationMetadataInterface $relation): bool
    {
        return \count($relation->relatedTypes()) === 1 && self::isPolymorphic($relation);
    }

    /**
     * The single related type of a monomorphic relation, or `null` for a polymorphic one
     * or one declaring no related types.
     */
    public static function monomorphicType(RelationMetadataInterface $relation): ?string
    {
        $types = $relation->relatedTypes();

        return \count($types) === 1 && !self::isPolymorphic($relation) ? $types[0] : null;
    }

    /**
     * Whether the relation's linkage carries a typed `meta.pivot`, and so has separate
     * response and request shapes: a monomorphic relation declaring pivot fields.
     */
    public static function hasPivot(RelationMetadataInterface $relation): bool
    {
        return $relation->pivotFields() !== [] && self::monomorphicType($relation) !== null;
    }
}
