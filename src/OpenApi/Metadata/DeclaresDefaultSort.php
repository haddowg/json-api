<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata naming the order the type's collection is read in when a request
 * names no sort, its resource's `defaultSort()`. The projector shows it as the `sort`
 * parameter's example on the type's collection endpoint, and on the related and
 * relationship endpoints of a relation to the type that declares no order of its own.
 *
 * A separate, opt-in contract rather than a method on {@see TypeMetadataInterface}, so
 * an existing implementation keeps working untouched, the same shape as
 * {@see DeclaresRelationDefaultSort}.
 */
interface DeclaresDefaultSort extends TypeMetadataInterface
{
    /**
     * The type's default order, most significant first, or `[]` when it declares none.
     * Report {@see \haddowg\JsonApi\Resource\AbstractResource::defaultSort()} for a type
     * backed by a resource.
     *
     * @return list<\haddowg\JsonApi\Resource\Sort\SortDirective>
     */
    public function defaultSort(): array;
}
