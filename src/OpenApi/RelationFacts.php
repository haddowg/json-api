<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

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
     * Whether the relation's linkage carries a typed `meta.pivot`, and so has separate
     * response and request shapes: a monomorphic relation declaring pivot fields.
     */
    public static function hasPivot(RelationMetadataInterface $relation): bool
    {
        return $relation->pivotFields() !== [] && \count($relation->relatedTypes()) === 1;
    }
}
