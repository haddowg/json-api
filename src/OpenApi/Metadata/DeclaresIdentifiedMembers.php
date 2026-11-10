<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Relation metadata that says whether a polymorphic to-many reads its members as stored
 * `[type, id]` pairs, a `MorphToMany` declared with `identifiedBy()`. Its relationship
 * endpoint pages the pairs like its related endpoint, so the projector gives that
 * operation the same page and `withCount` parameters, and the `filter` and `sort`
 * parameters of the relation's own filters and sorts. Report the pivot fields of its link
 * rows from {@see RelationMetadataInterface::pivotFields()}, its pivot sorts from
 * {@see RelationMetadataInterface::sorts()} and its `pivot.`-prefixed filters from
 * {@see RelationMetadataInterface::filters()}, as
 * {@see \haddowg\JsonApi\Collection\LinkRows\LinkRowQuery} resolves them.
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
