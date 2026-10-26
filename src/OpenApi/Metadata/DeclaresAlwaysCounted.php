<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Optional type or relation metadata saying whether the collection's paginator counts on
 * every request: a paginator built with `withCount()`, whose
 * {@see \haddowg\JsonApi\Pagination\PaginatorInterface::wantsCount()} is `true`. Implement
 * it on a {@see TypeMetadataInterface} for the primary collection, or on a
 * {@see RelationMetadataInterface} for a to-many's related and relationship collections.
 *
 * It changes only the operation examples: a collection that always counts shows its total
 * and `last` link without a `withCount` request. Metadata that does not implement it is
 * treated as count-free unless asked.
 */
interface DeclaresAlwaysCounted
{
    public function alwaysCounts(): bool;
}
