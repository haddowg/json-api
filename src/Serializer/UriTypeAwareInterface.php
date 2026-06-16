<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

/**
 * Optional capability: a serializer that declares the URI path *segment* for its
 * resource type, decoupled from the JSON:API `type` member. Core's by-convention
 * relationship links (and any host-generated routes) use this segment in the path
 * position, so a resource whose JSON:API type is `book` can live at `/books`
 * while its documents still carry `"type": "book"`.
 *
 * Optional by design: a serializer that does not implement it falls back to its
 * {@see SerializerInterface::getType()} value as the segment (today's behaviour),
 * so external serializers and bare serializer/hydrator pairs are unaffected.
 * {@see \haddowg\JsonApi\Resource\AbstractResource} implements it, defaulting the
 * segment to its `$type`.
 */
interface UriTypeAwareInterface
{
    /**
     * The URI path segment for this resource type (no surrounding slashes) —
     * e.g. `books` for the JSON:API type `book`.
     */
    public function uriType(): string;
}
