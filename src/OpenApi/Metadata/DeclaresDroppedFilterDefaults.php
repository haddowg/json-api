<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Relation metadata naming the related type's filter defaults the relation drops
 * through `withoutFilterDefaults()`. The projector leaves a dropped default off the
 * `filter[<key>]` parameters of the relation's related and relationship endpoints, since
 * those reads never apply it.
 *
 * A separate, opt-in contract rather than a method on {@see RelationMetadataInterface},
 * so an existing implementation keeps working untouched, the same shape as
 * {@see DeclaresRelationDefaultSort}. Report
 * {@see \haddowg\JsonApi\Resource\Field\DropsFilterDefaultsInterface} for a core relation.
 */
interface DeclaresDroppedFilterDefaults extends RelationMetadataInterface
{
    /**
     * Whether the relation drops every default its related types declare.
     */
    public function dropsAllFilterDefaults(): bool;

    /**
     * The filter keys whose related-type default the relation drops, or `[]` when it
     * names none.
     *
     * @return list<string>
     */
    public function droppedFilterDefaults(): array;
}
