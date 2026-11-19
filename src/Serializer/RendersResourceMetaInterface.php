<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A serializer that renders a resource object's whole `meta` itself, as one that wraps or
 * picks another serializer does, so the engine reaches the wrapped serializer's declared
 * {@see DeclaresResourceMetaInterface::resourceMeta()} members as well as its `getMeta()`.
 * Such a serializer forwards to {@see \haddowg\JsonApi\Resource\ResourceMeta::render()}
 * with the serializer it wraps.
 */
interface RendersResourceMetaInterface
{
    /**
     * The resource object's rendered `meta`, or an empty array to omit it.
     *
     * @return array<string, mixed>
     */
    public function renderResourceMeta(mixed $object, JsonApiRequestInterface $request): array;
}
