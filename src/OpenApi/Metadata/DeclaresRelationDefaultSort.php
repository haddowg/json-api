<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Relation metadata that names the order a to-many relation's members are read in when
 * a request names no sort, its `defaultSort()`. The projector writes it as the `default`
 * of the `sort` parameter on the relation's relationship and related endpoints.
 *
 * A separate, opt-in contract rather than a method on {@see RelationMetadataInterface},
 * so an existing implementation keeps working untouched, the same shape as
 * {@see DeclaresIdentifiedMembers}.
 */
interface DeclaresRelationDefaultSort extends RelationMetadataInterface
{
    /**
     * The relation's own declared default order, most significant first, or `[]` when
     * it declares none. Report
     * {@see \haddowg\JsonApi\Resource\Field\DefaultSortedRelationInterface::defaultSort()}
     * for a core relation. Leave out the related type's default: it isn't the
     * relation's, and the projection documents only what the relation declares.
     *
     * @return list<\haddowg\JsonApi\Resource\Sort\SortDirective>
     */
    public function defaultSort(): array;
}
