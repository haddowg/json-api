<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata naming the `sort` example the OpenAPI document shows for the type's
 * collections, in place of the one derived from its default order or its first sort.
 *
 * A separate, opt-in contract rather than a method on {@see TypeMetadataInterface}, so
 * an existing implementation keeps working untouched, the same shape as
 * {@see DeclaresSingularName}. Metadata that doesn't implement it, or returns `null`,
 * gets the derived example.
 */
interface DeclaresSortExample extends TypeMetadataInterface
{
    /**
     * The example's sort tokens, a leading `-` marking a descending key, or `null` for
     * the derived example. Mirror
     * {@see \haddowg\JsonApi\Resource\AbstractResource::sortExample()} when the type is
     * backed by a resource.
     *
     * @return list<string>|null
     */
    public function sortExample(): ?array;
}
