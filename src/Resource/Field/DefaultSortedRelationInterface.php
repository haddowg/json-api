<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A relation that can declare the order its members are read in when a request names
 * no sort, through {@see AbstractRelationBuilder::defaultSort()}. Every core relation
 * implements it, and a to-one always reports none.
 *
 * A separate interface rather than a method on {@see RelationInterface}, so a host's
 * own relation implementation keeps compiling. A data layer reads the order to apply
 * through {@see \haddowg\JsonApi\Resource\Sort\RelationSorts::defaultFor()}, which
 * falls back to the related type's default.
 */
interface DefaultSortedRelationInterface extends RelationInterface
{
    /**
     * The relation's own default read order, most significant first, or `[]` when it
     * declares none.
     *
     * @return list<\haddowg\JsonApi\Resource\Sort\SortDirective>
     */
    public function defaultSort(): array;
}
