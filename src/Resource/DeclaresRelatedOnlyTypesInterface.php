<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A {@see SerializerResolverInterface} that also says which types are related-only: types
 * that serve no endpoint of their own and are reached only as another type's related or
 * included resources. The transformer reads it off a rendered resource's injected resolver
 * and renders no by-convention link into such a type's endpoints, neither the resource
 * `self` nor a relationship object's `self`, `related` or page links. A `getLinks()` link
 * still renders.
 *
 * The {@see \haddowg\JsonApi\Server\Server} and its registry implement it. A resolver that
 * decorates the registry should implement it too and delegate, or the resources it renders
 * link to endpoints that don't exist.
 */
interface DeclaresRelatedOnlyTypesInterface
{
    /**
     * Whether the JSON:API type `$type` is related-only.
     */
    public function isRelatedOnly(string $type): bool;
}
