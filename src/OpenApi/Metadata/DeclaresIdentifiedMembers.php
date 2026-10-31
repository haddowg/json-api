<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Relation metadata that says whether a polymorphic to-many reads its members as stored
 * `[type, id]` pairs, a `MorphToMany` declared with `identifiedBy()`. Its relationship
 * endpoint pages the pairs like its related endpoint, so the projector gives that
 * operation the same page and `withCount` parameters.
 *
 * A separate, opt-in contract rather than a method on {@see RelationMetadataInterface},
 * so an existing implementation keeps working untouched, the same shape as
 * {@see DeclaresPolymorphicRelation}.
 */
interface DeclaresIdentifiedMembers extends RelationMetadataInterface
{
    /**
     * Whether the relation is a to-many whose members are identified by stored pairs.
     * Report {@see \haddowg\JsonApi\Resource\Field\StoresIdentifiersInterface::storesIdentifiers()}
     * for a core `MorphToMany`.
     */
    public function storesIdentifiers(): bool;
}
