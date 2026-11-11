<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A relation that can stop its related type's filter defaults applying to its reads,
 * through {@see AbstractRelationBuilder::withoutFilterDefaults()}. Every core relation
 * implements it.
 *
 * A separate interface rather than a method on {@see RelationInterface}, so a host's
 * own relation implementation keeps compiling. A data layer reads the filters to apply
 * through {@see \haddowg\JsonApi\Resource\Filter\RelationFilters::relatedFilters()}
 * rather than these accessors, so every adapter drops the same defaults.
 */
interface DropsFilterDefaultsInterface extends RelationInterface
{
    /**
     * Whether the relation drops every default its related types declare.
     */
    public function dropsAllFilterDefaults(): bool;

    /**
     * The filter keys whose related-type default the relation drops, in declaration
     * order, or `[]` when it names none.
     *
     * @return list<string>
     */
    public function droppedFilterDefaults(): array;
}
