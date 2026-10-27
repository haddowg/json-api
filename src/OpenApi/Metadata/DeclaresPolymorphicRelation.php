<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Relation metadata that says whether the relation is polymorphic by declaration, so
 * the projector can tell a `MorphTo` over one type from a `BelongsTo`.
 *
 * A separate, opt-in contract rather than a method on {@see RelationMetadataInterface},
 * so an existing implementation keeps working untouched, the same shape as
 * {@see DeclaresWriteOnlyRelation}. Metadata that doesn't implement it is polymorphic
 * only when it lists more than one related type, which is how every relation was
 * projected before.
 */
interface DeclaresPolymorphicRelation extends RelationMetadataInterface
{
    /**
     * Whether the relation is polymorphic by declaration, whatever the length of
     * {@see relatedTypes()}. A polymorphic relation is projected as a union of its
     * members even when it has one: its linkage identifier and related resource are an
     * `anyOf`, and its related and relationship endpoints take no filter, sort or page
     * parameters from a related type. Report `true` for a core
     * {@see \haddowg\JsonApi\Resource\Field\PolymorphicRelationInterface}. A relation
     * listing several types is projected as polymorphic whatever this returns.
     */
    public function isPolymorphic(): bool;
}
